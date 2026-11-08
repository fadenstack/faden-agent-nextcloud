import { describe, expect, it, vi } from 'vitest'
import { ApprovalPart } from '@fadenstack/ui'
import { FakeGateway } from '@fadenstack/testing'
import { createApp, markRaw } from 'vue'
import AgentChat from '../../src/components/AgentChat.vue'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { nativeProvidersApi, type ContentProposal, type NativeProvidersApi } from '../../src/providers/client.ts'
import type { FileApi, Identity } from '../../src/integrations/nextcloud/types.ts'
import type { ProviderResource } from '../../src/providers/scope.ts'

async function scenario(mode = '') {
	const tools = ['nc_windmill_read', 'nc_windmill_run'] as const
	const identity: Identity = { userId: 'alice', binding: 'native-session', enabled: true, agent: 'engine-runs', serverUrl: 'https://faden.test', development: true, providerReadsEnabled: true,
		scopePolicy: { version: 'run-test', gatewayOrigin: 'https://faden.test', sources: [{ kind: 'user-files' }], destinations: [], tools, sourceClasses: ['standard'], downstreamPolicy: 'unverified', destinationAudiences: ['unknown'], movements: [{ source: 'standard', destination: 'unknown' }], developmentLoopback: true } }
	const args = { connectionId: 'own', reference: 'u/alice/test', argsJson: '{}' }, jobId = '11111111-1111-4111-8111-111111111111'
	const resource: ProviderResource = { provider: 'windmill', collectionId: 'own', resourceId: 'nextcloud:windmill:own', revision: 'version-7', policyVersion: 'policy', sourceClass: 'standard', audience: 'unknown', filename: JSON.stringify({ connectionId: 'own', reference: args.reference }) }
	const native: NativeProvidersApi = {
		calendarCollections: vi.fn(async () => []), calendarEvents: vi.fn(async () => []),
		capabilities: vi.fn(async () => [{ provider: 'windmill', apiVersion: 'windmill-1.841.0-token', policyVersion: 'policy', enabled: true, tools: mode === 'read-only' ? [tools[0]] : tools, canRead: true, canWrite: mode !== 'read-only', history: 'audit-only', conflict: 'native', refresh: 'manual' }]),
		contentRead: vi.fn(async () => ({ resource })),
		contentPropose: vi.fn(async (): Promise<ContentProposal> => ({ id: 'a'.repeat(48), digest: 'b'.repeat(64), toolName: tools[1], provider: 'windmill', target: args.reference, before: null,
			payload: { argsJson: '{}', version: '7', jobId, workflowJson: '{"value":{"modules":[]}}' }, display: [{ label: 'Deployed flow version', before: null, after: '7' }, { label: 'Final run inputs', before: null, after: '{}' }], destinationAudience: 'unknown',
			resource, history: 'audit-only', limitation: 'Possible external effects; no Undo. Stop does not cancel a running job.', expires: Date.now() / 1000 + 600 })),
		contentApprove: vi.fn(async () => ({ approved: true })), contentCancel: vi.fn(async () => ({})),
		contentCommit: vi.fn(async () => { if (mode === 'lost-ack') throw Error('Acknowledgement lost'); return { provider: 'windmill' as const, target: args.reference, collectionId: 'own', history: 'audit-only' as const, outcome: 'verified' as const, continue: false, actionId: 'c'.repeat(32), result: { jobId, version: '7', dispatchVerified: true, externalEffectsVerified: false, executionState: mode === 'running' ? 'running' : 'succeeded' } } }),
	}
	const files: FileApi = { identity: async () => identity, assertIdentity: async () => {}, scopeResource: vi.fn(), select: vi.fn(), read: vi.fn(), list: vi.fn(), pdf: vi.fn(), propose: vi.fn(), approve: vi.fn(), commit: vi.fn(), nativeProviders: native }
	let round = 0
	const gateway = new FakeGateway({ agents: [{ slug: identity.agent, version: 1, contract: tools.map(name => ({ name, risk: name.endsWith('_read') ? 'read' : 'destructive' })) }], users: [{ email: 'alice', password: 'test', agents: [identity.agent] }], model: () => ++round === 1 ? { toolCalls: [{ id: 'read', name: tools[0], arguments: { connectionId: 'own', reference: args.reference } }] } : { toolCalls: [{ id: 'run', name: tools[1], arguments: args }] } })
	const host = new NextcloudAgent(identity, files, gateway.fetch)
	await host.signIn('alice', 'test')
	return { host, native, send: host.send('Run my configured synthetic flow once.', false) }
}
async function pending(host: NextcloudAgent) {
	let part: ApprovalPart | undefined
	await vi.waitFor(() => { part = host.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart; expect(part?.isPending).toBe(true) })
	return part!
}
describe('Governed native Windmill runs', () => {
	it.each(['succeeded', 'running', 'lost-ack'])('dispatches once for %s and never repeats an uncertain run', async mode => {
		const test = await scenario(mode)
		try { const part = await pending(test.host); expect(test.native.contentCommit).not.toHaveBeenCalled(); expect(part.request.canAllowAlways).toBe(false); await test.host.approve(part); await test.send; expect(test.native.contentCommit).toHaveBeenCalledTimes(1) }
		finally { test.host.stop(); await test.host.dispose() }
	})
	it.each(['deny', 'stop', 'changed-inputs'])('dispatches zero times after %s', async mode => {
		const test = await scenario()
		try {
			const part = await pending(test.host)
			if (mode === 'deny') await test.host.deny(part)
			else if (mode === 'stop') test.host.stop()
			else { part.request.arguments.content = { argsJson: '{"changed":true}' }; await expect(test.host.approve(part)).rejects.toThrow(); await test.host.deny(part) }
			await test.send; expect(test.native.contentCommit).not.toHaveBeenCalled()
		} finally { test.host.stop(); await test.host.dispose() }
	})
	it('shows Run rather than Save or Replace in the approval card', async () => {
		vi.stubGlobal('ResizeObserver', class { observe() {} unobserve() {} disconnect() {} })
		const test = await scenario(), root = document.createElement('div'); document.body.append(root)
		const app = createApp(AgentChat, { host: markRaw(test.host) })
		try {
			app.mount(root); await pending(test.host)
			await vi.waitFor(() => expect(root.querySelector('.fdn-approval')?.getAttribute('aria-label')).toBe('Run Windmill flow'))
			expect(root.querySelector('.fdn-approval')?.textContent).toContain('no Undo')
			expect(root.querySelector('.fdn-approval')?.textContent).toContain('Deployed flow version')
			test.host.stop(); await test.send
		} finally { test.host.stop(); app.unmount(); root.remove(); await test.host.dispose(); vi.unstubAllGlobals() }
	})
	it('omits run transport when native capability is read only', async () => {
		const test = await scenario('read-only')
		try { await test.send; expect(test.native.contentPropose).not.toHaveBeenCalled(); expect(test.native.contentCommit).not.toHaveBeenCalled() }
		finally { await test.host.dispose() }
	})
	it('refuses a run preview claiming a private effect audience', async () => {
		const post = vi.fn(async (_path: string, body: unknown) => ({ ...(body as object), digest: 'b'.repeat(64), id: (body as { requestId: string }).requestId, toolName: 'nc_windmill_run', provider: 'windmill', target: 'u/alice/test', history: 'audit-only', display: [], payload: {}, resource: {}, limitation: 'test', destinationAudience: 'private' }))
		await expect(nativeProvidersApi(post as Parameters<typeof nativeProvidersApi>[0]).contentPropose!('nc_windmill_run', {}, new AbortController().signal)).rejects.toThrow('Invalid native content preview')
	})
})
