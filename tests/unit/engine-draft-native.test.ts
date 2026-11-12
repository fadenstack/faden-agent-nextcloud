import { describe, expect, it, vi } from 'vitest'
import { ApprovalPart } from '@fadenstack/ui'
import { FakeGateway } from '../helpers/gateway071.ts'
import { createApp, markRaw } from 'vue'
import AgentChat from '../../src/components/AgentChat.vue'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { nativeProvidersApi, type ContentProposal, type NativeProvidersApi } from '../../src/providers/client.ts'
import type { FileApi, Identity } from '../../src/integrations/nextcloud/types.ts'
import type { ProviderResource } from '../../src/providers/scope.ts'

const identity: Identity = {
	userId: 'alice',
	binding: 'native-session',
	enabled: true,
	agent: 'engine-drafts',
	serverUrl: 'https://faden.test',
	development: true,
	providerReadsEnabled: true,
	scopePolicy: { version: 'test', gatewayOrigin: 'https://faden.test', sources: [{ kind: 'user-files' }], destinations: [], tools: ['nc_windmill_read', 'nc_windmill_draft', 'nc_n8n_read', 'nc_n8n_draft'], sourceClasses: ['standard'], downstreamPolicy: 'unverified', destinationAudiences: ['private'], movements: [{ source: 'standard', destination: 'private' }], developmentLoopback: true },
}

async function run(provider: 'windmill' | 'n8n', mode = '') {
	const toolName = `nc_${provider}_draft`; const args = mode === 'copy'
		? { connectionId: 'own', definitionJson: provider === 'windmill' ? '{"summary":"Copy"}' : '{"name":"Copy"}', sourceReference: provider === 'windmill' ? 'u/alice/source' : 'native-source', sourceRevision: 'd'.repeat(64), sourceKind: 'saved' }
		: { connectionId: 'own', definitionJson: provider === 'windmill' ? '{"summary":"New","value":{"modules":[]}}' : '{"name":"New","nodes":[{"id":"a","parameters":{}}],"connections":{},"settings":{}}' }
	let round = 0
	const reports: unknown[] = []
	const origin = {
		contract: `fadenstack/${provider}-origin/v1`,
		ai_generated: true,
		signed_record: {
			record: {
				contract: 'provenance/v1',
				id: '12345678-1234-4234-8234-123456789abc',
				media_type: provider === 'windmill' ? 'application/vnd.windmill.flow+json' : 'application/vnd.n8n.workflow+json',
				edit_kind: 'generated',
			},
		},
	}
	const markedJson = JSON.stringify(provider === 'windmill'
		? { schema: { 'x-fadenstack-provenance': origin } }
		: mode === 'placement-v2'
			? { description: 'User description', nodes: [{ id: 'a', notes: '<!-- fadenstack-provenance:' + btoa(JSON.stringify({ ...origin, contract: 'fadenstack/n8n-origin/v2', payload_profile: 'n8n-draft-source-json/v2' })) + ' -->' }] }
			: { description: '<!-- fadenstack-provenance:' + btoa(JSON.stringify(origin)) + ' -->' })
	const resource: ProviderResource = { provider, collectionId: 'own', resourceId: `nextcloud:${provider}:b3du`, revision: 'revision-1', policyVersion: 'native-policy', sourceClass: 'standard', audience: 'private', filename: '{"connectionId":"own"}' }
	const native: NativeProvidersApi = {
		calendarCollections: vi.fn(async () => []),
		calendarEvents: vi.fn(async () => []),
		capabilities: vi.fn(async () => [{ provider, apiVersion: `${provider}-${provider === 'windmill' ? '1.841.0' : '2.43.8'}-token`, policyVersion: 'native-policy', enabled: true, tools: [`nc_${provider}_read`, ...(mode === 'read-only' ? [] : [toolName])], canRead: true, canWrite: mode !== 'read-only', history: 'audit-only', conflict: 'native', refresh: 'manual' }]),
		contentRead: vi.fn(async (_provider, input) => ({ resource: Object.keys(input).length ? resource : { ...resource, collectionId: 'connections', resourceId: `nextcloud:${provider}:e30`, filename: '{}', audience: 'unknown' } })),
		contentPropose: vi.fn(async (): Promise<ContentProposal> => ({ id: 'a'.repeat(48), digest: 'b'.repeat(64), toolName, provider, target: 'New private inactive draft', before: null, payload: { definitionJson: 'final-marked-native-json' }, display: [{ label: 'Final workflow JSON including AI origin', before: null, after: 'final-marked-native-json' }], resource, history: 'audit-only', limitation: 'New draft only; no deployment, execution or Undo.', expires: Date.now() / 1000 + 600 })),
		contentApprove: vi.fn(async () => ({ approved: true })),
		contentCancel: vi.fn(async () => ({})),
		contentCommit: vi.fn(async () => { if (mode === 'lost-ack') throw Error('Lost engine acknowledgement'); return { provider, target: 'New private inactive draft', collectionId: 'own', history: 'audit-only', outcome: 'verified', continue: false, actionId: 'c'.repeat(32), result: { reference: 'new-native-draft', inactiveDraft: true, originStored: true } } }),
	}
	const files: FileApi = { identity: async () => identity, assertIdentity: async () => {}, scopeResource: vi.fn(), select: vi.fn(), read: vi.fn(), list: vi.fn(), pdf: vi.fn(), propose: vi.fn(), approve: vi.fn(), commit: vi.fn(), nativeProviders: native }
	const gateway = new FakeGateway({ agents: [{ slug: identity.agent, version: 1, contract: identity.scopePolicy!.tools.map(name => ({ name, risk: name.endsWith('_read') ? 'read' : 'destructive' })) }], users: [{ email: 'alice', password: 'test', agents: [identity.agent] }], model: () => ++round === 1 ? { toolCalls: [{ id: 'discover', name: `nc_${provider}_read`, arguments: {} }] } : round === 2 ? { toolCalls: [{ id: 'new-draft', name: toolName, arguments: args }] } : { text: 'Finished.' } })
	if (mode.startsWith('placement')) {
		vi.mocked(native.contentPropose!).mockImplementation(async () => ({
			id: 'a'.repeat(48),
			digest: 'b'.repeat(64),
			toolName,
			provider,
			target: 'New private inactive draft',
			before: null,
			payload: { definitionJson: markedJson },
			resource,
			history: 'audit-only',
			limitation: 'Audit only',
			expires: Date.now() / 1000 + 600,
		}))
	}
	const fetch: typeof gateway.fetch = async (input, init) => {
		if (new URL(String(input)).pathname.endsWith('/provenance/placements')) {
			expect(native.contentCommit).toHaveBeenCalledTimes(1)
			reports.push(JSON.parse(String(init?.body)))
			return new Response(JSON.stringify(mode === 'placement-failure' ? { error: 'unavailable' } : { stored: true }), { status: mode === 'placement-failure' ? 503 : 202, headers: { 'Content-Type': 'application/json' } })
		}
		return gateway.fetch(input, init)
	}
	const host = new NextcloudAgent(identity, files, fetch)
	await host.signIn('alice', 'test')
	return { host, native, reports, send: host.send('Create a new private draft in my own engine connection.', false) }
}
async function pending(host: NextcloudAgent) {
	let part: ApprovalPart | undefined
	await vi.waitFor(() => { part = host.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart; expect(part?.isPending).toBe(true) })
	return part!
}
describe('Engine drafts through published SDK approval', () => {
	it.each(['windmill', 'n8n'] as const)('passes the native %s source binding and minimal replacements through individual approval once', async provider => {
		const test = await run(provider, 'copy')
		try {
			const approval = await pending(test.host)
			expect(test.native.contentPropose).toHaveBeenCalledWith(`nc_${provider}_draft`, expect.objectContaining({ sourceReference: provider === 'windmill' ? 'u/alice/source' : 'native-source', sourceRevision: 'd'.repeat(64), sourceKind: 'saved', definitionJson: provider === 'windmill' ? '{"summary":"Copy"}' : '{"name":"Copy"}' }), expect.any(AbortSignal))
			expect(test.native.contentCommit).not.toHaveBeenCalled()
			await test.host.approve(approval); await test.send
			expect(test.native.contentCommit).toHaveBeenCalledTimes(1)
		} finally { test.host.stop(); await test.host.dispose() }
	})
	it('reports the new n8n note carrier through composed individual approval and the published client', async () => {
		const test = await run('n8n', 'placement-v2')
		try {
			const approval = await pending(test.host)
			expect(test.reports).toEqual([])
			await test.host.approve(approval); await test.send
			expect(test.native.contentCommit).toHaveBeenCalledTimes(1)
			expect(test.reports).toHaveLength(1)
			expect(test.reports[0]).toMatchObject({ layers: [{ method: 'n8n-node-notes-comment', applied: true, verified: true }] })
		} finally { test.host.stop(); await test.host.dispose() }
	})
	it.each(['windmill', 'n8n'] as const)('reports %s placement once through the published client after verified native commitment', async provider => {
		const test = await run(provider, 'placement')
		try {
			const approval = await pending(test.host)
			expect(test.reports).toEqual([])
			await test.host.approve(approval); await test.send
			expect(test.reports).toHaveLength(1)
			expect(test.reports[0]).toMatchObject({ tool: `nc_${provider}_draft`, written: true, layers: [{ applied: true, verified: true }] })
			expect(test.native.contentCommit).toHaveBeenCalledTimes(1)
		} finally { test.host.stop(); await test.host.dispose() }
	})
	it('does not report denied origin as written', async () => {
		const test = await run('windmill', 'placement')
		try { await test.host.deny(await pending(test.host)); await test.send; expect(test.reports).toEqual([]); expect(test.native.contentCommit).not.toHaveBeenCalled() } finally { test.host.stop(); await test.host.dispose() }
	})
	it('retains one native commitment when the placement service fails', async () => {
		const test = await run('n8n', 'placement-failure')
		try {
			await test.host.approve(await pending(test.host)); await test.send
			expect(test.native.contentCommit).toHaveBeenCalledTimes(1); expect(test.reports).toHaveLength(1)
			expect(test.host.chat.turns.flatMap(turn => turn.parts).filter(part => 'text' in part).map(part => part.text).join(' ')).toContain('placement report is unconfirmed')
		} finally { test.host.stop(); await test.host.dispose() }
	})
	it.each(['windmill', 'n8n'] as const)('renders %s as a draft with inspectable final JSON', async provider => {
		vi.stubGlobal('ResizeObserver', class {

			observe() {} unobserve() {} disconnect() {}

		})
		const test = await run(provider); const root = document.createElement('div'); document.body.append(root)
		const app = createApp(AgentChat, { host: markRaw(test.host) })
		try {
			app.mount(root); await pending(test.host)
			await vi.waitFor(() => expect(root.querySelector('.fdn-approval')?.getAttribute('aria-label')).toBe(provider === 'windmill' ? 'Create Windmill draft' : 'Create n8n draft'))
			expect(root.querySelector('.fdn-approval')?.textContent).toContain('Audit only')
			expect([...root.querySelectorAll('summary')].some(s => s.textContent === 'Review exact workflow JSON')).toBe(true)
			expect(root.querySelector('.fdn-approval')?.textContent).not.toContain('Replace file:')
			test.host.stop(); await test.send
		} finally { test.host.stop(); app.unmount(); root.remove(); await test.host.dispose(); vi.unstubAllGlobals() }
	})
	it.each(['windmill', 'n8n'] as const)('shows the final transformed %s payload and dispatches once after Allow', async provider => {
		const test = await run(provider)
		try {
			const part = await pending(test.host)
			expect(test.native.contentCommit).not.toHaveBeenCalled()
			expect(part.request).toMatchObject({ canAllowAlways: false, canAllowForSession: false, arguments: { content: { definitionJson: 'final-marked-native-json' } } })
			await test.host.approve(part); await test.send
			expect(test.native.contentApprove).toHaveBeenCalledTimes(1); expect(test.native.contentCommit).toHaveBeenCalledTimes(1)
		} finally { test.host.stop(); await test.host.dispose() }
	})
	it.each(['deny', 'stop', 'tamper'] as const)('dispatches no draft after %s', async mode => {
		const test = await run('windmill')
		try {
			const part = await pending(test.host)
			if (mode === 'stop') test.host.stop()
			else if (mode === 'deny') await test.host.deny(part)
			else { part.request.arguments.content = { definitionJson: 'changed-after-preview' }; await expect(test.host.approve(part)).rejects.toThrow(); await test.host.deny(part) }
			await test.send; expect(test.native.contentCommit).not.toHaveBeenCalled()
		} finally { test.host.stop(); await test.host.dispose() }
	})
	it('does not repeat an uncertain native draft create', async () => {
		const test = await run('n8n', 'lost-ack')
		try { await test.host.approve(await pending(test.host)); await test.send; expect(test.native.contentCommit).toHaveBeenCalledTimes(1) } finally { test.host.stop(); await test.host.dispose() }
	})
	it('cannot restore draft transport from read-only native capability', async () => {
		const test = await run('n8n', 'read-only')
		try { await test.send; expect(test.native.contentPropose).not.toHaveBeenCalled(); expect(test.native.contentCommit).not.toHaveBeenCalled() } finally { await test.host.dispose() }
	})
	it('uses a request-local cloud-authenticated native proposal route only for engines', async () => {
		const regular = vi.fn(async () => ({})); const authenticated = vi.fn(async () => { throw Error('Synthetic refusal') })
		const native = nativeProvidersApi(regular as Parameters<typeof nativeProvidersApi>[0], authenticated)
		await expect(native.contentPropose!('nc_windmill_draft', { connectionId: 'own', definitionJson: '{}' }, new AbortController().signal)).rejects.toThrow('Synthetic refusal')
		expect(authenticated).toHaveBeenCalledOnce(); expect(authenticated.mock.calls[0]?.[0]).toBe('/providers/engines/propose')
		expect(regular).toHaveBeenCalledOnce(); expect(regular.mock.calls[0]?.[0]).toMatch(/^\/providers\/engines\/[a-f0-9]{48}\/cancel$/)
	})
})
