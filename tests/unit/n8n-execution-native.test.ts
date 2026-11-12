import { describe, expect, it, vi } from 'vitest'
import { ApprovalPart } from '@fadenstack/ui'
import { FakeGateway } from '../helpers/gateway071.ts'
import { createApp, markRaw } from 'vue'
import AgentChat from '../../src/components/AgentChat.vue'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import type { ContentProposal, NativeProvidersApi } from '../../src/providers/client.ts'
import type { FileApi, Identity } from '../../src/integrations/nextcloud/types.ts'
import type { ProviderResource } from '../../src/providers/scope.ts'

async function scenario(mode = '') {
	const tools = ['nc_n8n_read', 'nc_n8n_execute'] as const
	const identity: Identity = { userId: 'alice', binding: 'native-session', enabled: true, agent: 'n8n-execution', serverUrl: 'https://faden.test', development: true, providerReadsEnabled: true,
		scopePolicy: { version: 'test', gatewayOrigin: 'https://faden.test', sources: [{ kind: 'user-files' }], destinations: [], tools, sourceClasses: ['standard'], downstreamPolicy: 'unverified', destinationAudiences: ['unknown'], movements: [{ source: 'standard', destination: 'unknown' }], developmentLoopback: true } }
	const args = { targetId: 'own-hook', dataJson: '{"synthetic":true}' }
	const resource: ProviderResource = { provider: 'n8n', collectionId: 'own', resourceId: 'nextcloud:n8n:own', revision: 'published-1', policyVersion: 'policy', sourceClass: 'standard', audience: 'unknown', filename: '{"connectionId":"own","reference":"flow1"}' }
	const native: NativeProvidersApi = {
		calendarCollections: vi.fn(async () => []), calendarEvents: vi.fn(async () => []),
		capabilities: vi.fn(async () => [{ provider: 'n8n', apiVersion: 'n8n-2.43.8-token', policyVersion: 'policy', enabled: true, tools: mode === 'read-only' ? [tools[0]] : tools, canRead: true, canWrite: mode !== 'read-only', history: 'audit-only', conflict: 'native', refresh: 'manual' }]),
		contentRead: vi.fn(async () => ({ resource, executionTargets: [{ id: 'own-hook', workflowId: 'flow1' }] })),
		contentPropose: vi.fn(async (): Promise<ContentProposal> => ({ id: 'a'.repeat(48), digest: 'b'.repeat(64), toolName: tools[1], provider: 'n8n', target: 'flow1', before: null,
			payload: { bodyJson: '{"_fadenstack":{"actionId":"synthetic"},"data":{"synthetic":true}}', version: 'published-1', workflowJson: '{}' }, display: [{ label: 'Published webhook target', before: null, after: 'own-hook' }], destinationAudience: 'unknown', resource, history: 'audit-only', limitation: 'Native last-write-wins; external effects unknown; no Undo.', expires: Date.now() / 1000 + 600 })),
		contentApprove: vi.fn(async () => ({ approved: true })), contentCancel: vi.fn(async () => ({})),
		contentCommit: vi.fn(async () => { if (mode === 'lost-ack') throw Error('Acknowledgement lost'); return { provider: 'n8n' as const, target: 'flow1', collectionId: 'own', history: 'audit-only' as const, outcome: 'verified' as const, continue: false, actionId: 'c'.repeat(32), result: { executionId: '7', version: 'published-1', dispatchVerified: true, externalEffectsVerified: false, executionState: 'succeeded' } } }),
	}
	const files: FileApi = { identity: async () => identity, assertIdentity: async () => {}, scopeResource: vi.fn(), select: vi.fn(), read: vi.fn(), list: vi.fn(), pdf: vi.fn(), propose: vi.fn(), approve: vi.fn(), commit: vi.fn(), nativeProviders: native }
	let round = 0
	const gateway = new FakeGateway({ agents: [{ slug: identity.agent, version: 1, contract: tools.map(name => ({ name, risk: name.endsWith('_read') ? 'read' : 'destructive' })) }], users: [{ email: 'alice', password: 'test', agents: [identity.agent] }], model: () => ++round === 1 ? { toolCalls: [{ id: 'read', name: tools[0], arguments: { connectionId: 'own', reference: 'flow1' } }] } : { toolCalls: [{ id: 'execute', name: tools[1], arguments: args }] } })
	const host = new NextcloudAgent(identity, files, gateway.fetch)
	await host.signIn('alice', 'test')
	return { host, native, send: host.send('Execute my configured personal webhook once.', false) }
}
async function pending(host: NextcloudAgent) {
	let part: ApprovalPart | undefined
	await vi.waitFor(() => { part = host.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart; expect(part?.isPending).toBe(true) })
	return part!
}
describe('Published SDK n8n execution governance', () => {
	it.each(['verified', 'lost-ack'])('dispatches only once with a %s receipt', async mode => {
		const test = await scenario(mode)
		try { const part = await pending(test.host); expect(test.native.contentCommit).not.toHaveBeenCalled(); expect(part.request.canAllowAlways).toBe(false); await test.host.approve(part); await test.send; expect(test.native.contentCommit).toHaveBeenCalledTimes(1) }
		finally { test.host.stop(); await test.host.dispose() }
	})
	it.each(['deny', 'stop', 'tamper'])('dispatches zero times after %s', async mode => {
		const test = await scenario()
		try {
			const part = await pending(test.host)
			if (mode === 'deny') await test.host.deny(part)
			else if (mode === 'stop') test.host.stop()
			else { part.request.arguments.content = { bodyJson: '{}' }; await expect(test.host.approve(part)).rejects.toThrow(); await test.host.deny(part) }
			await test.send; expect(test.native.contentCommit).not.toHaveBeenCalled()
		} finally { test.host.stop(); await test.host.dispose() }
	})
	it('withholds the execution transport when native grants are absent', async () => {
		const test = await scenario('read-only')
		try { await test.send; expect(test.native.contentPropose).not.toHaveBeenCalled(); expect(test.native.contentCommit).not.toHaveBeenCalled() }
		finally { await test.host.dispose() }
	})
	it('names the native preview as an n8n run with its webhook target', async () => {
		vi.stubGlobal('ResizeObserver', class { observe() {} unobserve() {} disconnect() {} })
		const test = await scenario(), root = document.createElement('div'); document.body.append(root)
		const app = createApp(AgentChat, { host: markRaw(test.host) })
		try { app.mount(root); await pending(test.host); await vi.waitFor(() => expect(root.querySelector('.fdn-approval')?.getAttribute('aria-label')).toBe('Run n8n workflow')); expect(root.querySelector('.fdn-approval')?.textContent).toContain('Published webhook target'); test.host.stop(); await test.send }
		finally { test.host.stop(); app.unmount(); root.remove(); await test.host.dispose(); vi.unstubAllGlobals() }
	})
})
