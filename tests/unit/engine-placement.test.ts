import { describe, expect, it, vi } from 'vitest'
import type { PlacementReport } from '@fadenstack/client'
import { reportEnginePlacement } from '../../src/providers/enginePlacement.ts'
import type { ContentOutcome, ContentProposal } from '../../src/providers/client.ts'

function fixture(provider: 'windmill' | 'n8n') {
	const origin = {
		contract: `fadenstack/${provider}-origin/v1`,
		ai_generated: true,
		signed_record: {
			record: {
				contract: 'provenance/v1',
				id: '12345678-1234-4234-8234-123456789abc',
				edit_kind: 'generated',
				media_type: provider === 'windmill' ? 'application/vnd.windmill.flow+json' : 'application/vnd.n8n.workflow+json',
			},
			signature: 'native-verified-signature',
		},
		previous_unverified: { private: 'must-never-be-reported' },
	}
	const value = provider === 'windmill'
		? { schema: { 'x-fadenstack-provenance': origin }, value: { modules: [] } }
		: { description: 'Private workflow\n<!-- fadenstack-provenance:' + btoa(JSON.stringify(origin)) + ' -->', nodes: [], connections: {} }
	const proposal = { provider, toolName: `nc_${provider}_draft`, target: 'private-native-target', payload: { definitionJson: JSON.stringify(value) } } as ContentProposal
	const receipt: ContentOutcome = {
		provider,
		target: proposal.target,
		actionId: 'a'.repeat(32),
		history: 'audit-only',
		outcome: 'verified',
		continue: false,
		result: { inactiveDraft: true, originStored: true },
	}
	return { proposal, receipt, report: vi.fn(async (placement: PlacementReport, signal: AbortSignal) => { void placement; void signal }), signal: new AbortController().signal }
}

describe('Verified engine placement audit', () => {
	it('reports an exportable v2 node-note carrier without requiring a description marker', async () => {
		const f = fixture('n8n'); const value = JSON.parse(String(f.proposal.payload.definitionJson))
		const origin = JSON.parse(atob(value.description.match(/fadenstack-provenance:([A-Za-z0-9+/=]+)/)[1]))
		origin.contract = 'fadenstack/n8n-origin/v2'; origin.payload_profile = 'n8n-draft-source-json/v2'
		origin.previous_unverified.label = 'Grüße 世界'
		const encoded = btoa(String.fromCharCode(...new TextEncoder().encode(JSON.stringify(origin))))
		value.description = 'User description stays clean'
		value.nodes = [{ id: 'anchor', notes: 'User notes\n<!-- fadenstack-provenance:' + encoded + ' -->' }]
		expect(await reportEnginePlacement({ ...f.proposal, payload: { definitionJson: JSON.stringify(value) } }, f.receipt, f.report, f.signal)).toBe('reported')
		expect(f.report).toHaveBeenCalledTimes(1)
		expect(f.report.mock.calls[0]?.[0]).toMatchObject({ layers: [{ method: 'n8n-node-notes-comment', applied: true, verified: true }] })
		expect(JSON.stringify(f.report.mock.calls)).not.toMatch(/Grüße|世界|User description|User notes|previous_unverified/)
	})
	it.each(['v2-description-only', 'unsupported-node-profile'])('does not invent an exportable layer for %s', async mode => {
		const f = fixture('n8n'); const value = JSON.parse(String(f.proposal.payload.definitionJson))
		const origin = JSON.parse(atob(value.description.match(/fadenstack-provenance:([A-Za-z0-9+/=]+)/)[1]))
		origin.contract = 'fadenstack/n8n-origin/v2'; origin.payload_profile = mode === 'unsupported-node-profile' ? 'unknown' : 'n8n-draft-source-json/v2'
		const comment = '<!-- fadenstack-provenance:' + btoa(JSON.stringify(origin)) + ' -->'
		value.description = mode === 'v2-description-only' ? comment : 'User description'
		value.nodes = mode === 'v2-description-only' ? [] : [{ id: 'anchor', notes: comment }]
		expect(await reportEnginePlacement({ ...f.proposal, payload: { definitionJson: JSON.stringify(value) } }, f.receipt, f.report, f.signal)).toBe('unconfirmed')
		expect(f.report).not.toHaveBeenCalled()
	})
	it.each(['windmill', 'n8n'] as const)('reports the %s carrier only after an exact verified receipt', async provider => {
		const f = fixture(provider)
		expect(await reportEnginePlacement(f.proposal, f.receipt, f.report, f.signal)).toBe('reported')
		expect(f.report).toHaveBeenCalledTimes(1)
		const [body, signal] = f.report.mock.calls[0] as unknown as [Record<string, unknown>, AbortSignal]
		expect(body).toEqual({
			record_id: '12345678-1234-4234-8234-123456789abc',
			tool: `nc_${provider}_draft`,
			sink_kind: `nextcloud.${provider}.draft`,
			media_type: provider === 'windmill' ? 'application/vnd.windmill.flow+json' : 'application/vnd.n8n.workflow+json',
			edit_kind: 'generated',
			length_class: 'medium',
			written: true,
			layers: [{ layer: 'metadata', method: provider === 'windmill' ? 'windmill-schema-extension' : 'n8n-description-comment', applied: true, verified: true }],
		})
		expect(JSON.stringify(body)).not.toMatch(/private-native-target|must-never-be-reported|Private workflow|signature|definitionJson/)
		expect(signal).toBeInstanceOf(AbortSignal)
	})
	it.each([
		{ outcome: 'unknown' }, { continue: true }, { provider: 'n8n' }, { target: 'changed-target' }, { actionId: 'invalid' },
		{ result: { inactiveDraft: true, originStored: false } }, { result: { inactiveDraft: false, originStored: true } },
	] as const)('never reports a successful placement for an uncertain or mismatched receipt: %j', async change => {
		const f = fixture('windmill')
		expect(await reportEnginePlacement(f.proposal, { ...f.receipt, ...change }, f.report, f.signal)).toBe('unconfirmed')
		expect(f.report).not.toHaveBeenCalled()
	})
	it('does not fabricate a failed placement when reporting times out', async () => {
		const f = fixture('windmill'); const report = vi.fn(async () => { throw new DOMException('Lost report acknowledgement', 'TimeoutError') })
		expect(await reportEnginePlacement(f.proposal, f.receipt, report, f.signal)).toBe('unconfirmed')
		expect(report).toHaveBeenCalledTimes(1)
		expect(f.receipt.outcome).toBe('verified')
	})
	it('makes no new request after Stop', async () => {
		const f = fixture('windmill'); const stop = new AbortController(); stop.abort()
		expect(await reportEnginePlacement(f.proposal, f.receipt, f.report, stop.signal)).toBe('unconfirmed')
		expect(f.report).not.toHaveBeenCalled()
	})
	it.each(['invalid-json', '{"schema":{}}', 'null'])('does not report malformed or missing carriers: %s', async definitionJson => {
		const f = fixture('windmill')
		expect(await reportEnginePlacement({ ...f.proposal, payload: { definitionJson } }, f.receipt, f.report, f.signal)).toBe('unconfirmed')
		expect(f.report).not.toHaveBeenCalled()
	})
	it.each(['nc_windmill_run', 'nc_n8n_execute'])('does not label execution as generated content: %s', async toolName => {
		const f = fixture('windmill')
		expect(await reportEnginePlacement({ ...f.proposal, toolName }, f.receipt, f.report, f.signal)).toBe('not-applicable')
		expect(f.report).not.toHaveBeenCalled()
	})
})
