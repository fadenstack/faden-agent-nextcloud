import { describe, expect, it, vi } from 'vitest'
import { ApprovalPart } from '@fadenstack/ui'
import { createApp, markRaw } from 'vue'
import AgentChat from '../../src/components/AgentChat.vue'
import { FakeGateway } from '@fadenstack/testing'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { WorkspaceCoordinator } from '../../src/workspace/coordinator.ts'
import type { FileApi, Identity } from '../../src/integrations/nextcloud/types.ts'
import type { ContentProposal, ContentProviderId, NativeProvidersApi } from '../../src/providers/client.ts'
import type { ProviderResource } from '../../src/providers/scope.ts'

const specs = [
	['nc_collectives_create_page', { collectiveId: 1, title: 'New leaf' }],
	['nc_collectives_delete_page', { pageId: 2 }],
	['nc_forms_create_form', { title: 'Private form', questions: [{ text: 'Choice', type: 'multiple', mandatory: true, options: ['A', 'B'] }] }],
	['nc_forms_submit_response', { formId: 1, answers: { '1': ['2'] } }],
	['nc_notes_create', { title: 'New note', content: 'Exact content' }],
	['nc_bookmarks_create', { url: 'https://example.invalid/a', title: 'New bookmark', tags: ['synthetic'] }],
	['nc_whiteboard_create', { path: '/', name: 'New.whiteboard' }],
	['nc_flow_create_draft', { description: 'Tag synthetic files', operation: 'tag_file', config: { filename: 'synthetic.txt', tagId: 1 } }],
	['nc_flow_update_rule', { ruleId: 2, active: true }],
] as const
const tools = ['nc_collectives_read', 'nc_forms_read', 'nc_notes_read', 'nc_bookmarks_read', 'nc_whiteboard_read', 'nc_flow_read', ...specs.map(s => s[0])]
const identity: Identity = { userId: 'alice', binding: 'native-session', enabled: true, agent: 'content', serverUrl: 'https://faden.test', development: true, providerReadsEnabled: true,
	scopePolicy: { version: 'test', gatewayOrigin: 'https://faden.test', sources: [{ kind: 'user-files' }], destinations: [], tools, sourceClasses: ['standard'], downstreamPolicy: 'unverified', destinationAudiences: ['private', 'unknown', 'shared'], movements: ['private', 'unknown', 'shared'].map(destination => ({ source: 'standard' as const, destination: destination as 'private' | 'unknown' | 'shared' })), developmentLoopback: true } }
async function flow(name: string, args: Readonly<Record<string, unknown>>, option = '') {
	const config: Identity = option === 'protected-source' ? { ...identity, scopePolicy: { ...identity.scopePolicy!, sourceClasses: ['standard', 'protected'], downstreamPolicy: 'configured' } } : identity
	const provider = name.split('_')[1] as ContentProviderId
	const version = { collectives: '4.7.1', forms: '5.4.1', notes: '6.1.0', bookmarks: '17.0.0', whiteboard: '2.1.2', flow: '3.0.0' }[provider]
	const resource: ProviderResource = { provider, resourceId: `nextcloud:${provider}:MQ`, collectionId: provider === 'collectives' ? '1' : 'personal', revision: 'a'.repeat(64), policyVersion: 'native-policy', sourceClass: 'standard', audience: 'unknown', filename: '{}' }
	let agent: NextcloudAgent, step = 0
	const native: NativeProvidersApi = {
		capabilities: vi.fn(async () => option === 'unavailable' ? [] : [{ provider, apiVersion: `${provider}-${version}-session`, policyVersion: 'native-policy', enabled: true, tools: tools.filter(t => t.startsWith(`nc_${provider}_`)), conflict: 'native', history: 'audit-only', refresh: 'child-reload', canRead: true, canWrite: true }]),
		calendarCollections: vi.fn(async () => []), calendarEvents: vi.fn(async () => []), calendarEvent: vi.fn(), calendarPropose: vi.fn(), calendarApprove: vi.fn(), calendarCommit: vi.fn(),
		contentRead: vi.fn(async () => ({ resource })),
		contentPropose: vi.fn(async (toolName, input): Promise<ContentProposal> => ({ id: 'b'.repeat(48), digest: 'c'.repeat(64), provider, toolName, target: 'Native synthetic content', before: null, payload: { ...input }, resource, expires: Date.now() / 1000 + 600, history: 'audit-only', limitation: 'Native last-write-wins; no Undo.', display: [{ label: 'Final payload', before: null, after: input }] })),
		contentApprove: vi.fn(async () => ({ approved: true })), contentCancel: vi.fn(async () => ({})),
		contentCommit: vi.fn(async () => {
			step++
			if (option === 'lost-ack') throw new Error('Lost native acknowledgement')
			if (option === 'stop') agent.stop()
			const more = name === 'nc_forms_create_form' && step < 5
			return { outcome: option === 'accepted-unknown' ? 'unknown' : more ? 'running' : 'verified', continue: more, actionId: 'e'.repeat(32), provider, collectionId: resource.collectionId, target: 'Native synthetic content', result: { id: 8 }, history: 'audit-only' }
		}),
	}
	const files: FileApi = { identity: async () => config, assertIdentity: async () => {}, select: vi.fn(), read: vi.fn(), list: vi.fn(), pdf: vi.fn(), propose: vi.fn(), approve: vi.fn(), commit: vi.fn(), nativeProviders: native, scopeResource: vi.fn(async () => ({ resourceId: 'nextcloud:files:1', revision: 'source-revision', sourceClass: 'protected' as const, audience: 'private' as const, scope: { kind: 'user-files' as const } })) }
	let round = 0
	const gateway = new FakeGateway({ serverUrl: identity.serverUrl, agents: [{ slug: identity.agent, version: 1, contract: tools.map(name => ({ name, risk: name.endsWith('_read') ? 'read' : 'destructive' })) }], users: [{ email: 'alice', password: 'test', agents: [identity.agent] }], model: () => ++round === 1 ? { toolCalls: [{ id: 'read', name: `nc_${provider}_read`, arguments: {} }] } : round === 2 ? { toolCalls: [{ id: 'write', name, arguments: args }] } : { text: 'Finished.' } })
	const workspace = new WorkspaceCoordinator({ guard: { snapshot: () => ({ app: provider === 'whiteboard' ? 'files' : provider, key: 'native/1', epoch: 1, editState: 'clean', reason: '' }), dispose: () => {} }, refresh: vi.fn(async () => 'refreshed') })
	agent = new NextcloudAgent(config, files, gateway.fetch, workspace)
	await agent.signIn('alice', 'test')
	return { agent, native, files, workspace, send: agent.send('Perform this synthetic native content operation', false) }
}
async function pending(agent: NextcloudAgent): Promise<ApprovalPart> {
	let part: ApprovalPart | undefined
	await vi.waitFor(() => { part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart; expect(part?.isPending).toBe(true) })
	return part!
}
describe('Native content tools through published SDK governance', () => {
	it('rechecks earlier Files sources and refuses a disallowed protected-to-content movement at Allow', async () => {
		const run = await flow(specs[4][0], specs[4][1], 'protected-source')
		try {
			const part = await pending(run.agent)
			await run.agent.rememberSource('nextcloud:files:1', new AbortController().signal)
			await expect(run.agent.approve(part)).rejects.toThrow('cannot be copied')
			expect(run.native.contentApprove).not.toHaveBeenCalled(); expect(run.native.contentCommit).not.toHaveBeenCalled()
			await run.agent.deny(part); await run.send
		} finally { run.agent.stop(); await run.agent.dispose() }
	})
	it.each(specs)('routes the real chat Allow button through native approval for %s', async (name, args) => {
		vi.stubGlobal('ResizeObserver', class { observe() {} unobserve() {} disconnect() {} })
		const run = await flow(name, args)
		const root = document.createElement('div'); document.body.append(root)
		const app = createApp(AgentChat, { host: markRaw(run.agent) })
		try {
			app.mount(root); await pending(run.agent)
			await vi.waitFor(() => expect(root.querySelector('.fadenstack-file-fields')?.textContent).toContain('Final payload'))
			expect(root.querySelector('.fdn-approval')?.textContent).toContain('Audit only')
			expect(run.native.contentCommit).not.toHaveBeenCalled()
			const allow = [...root.querySelectorAll('button')].find(button => button.textContent?.trim() === 'Allow')
			expect(allow).toBeTruthy(); allow!.click()
			await vi.waitFor(() => expect(run.native.contentApprove).toHaveBeenCalledTimes(1))
			await run.send
			expect(run.native.contentCommit).toHaveBeenCalledTimes(name === 'nc_forms_create_form' ? 5 : 1)
		} finally { run.agent.stop(); await run.send; app.unmount(); root.remove(); await run.agent.dispose(); vi.unstubAllGlobals() }
	})
	it.each(specs)('requires one exact native approval for %s', async (name, args) => {
		const run = await flow(name, args)
		try {
			const part = await pending(run.agent)
			expect(run.native.contentCommit).not.toHaveBeenCalled()
			expect(part.request).toMatchObject({ canAllowAlways: false, canAllowForSession: false })
			await run.agent.approve(part); await run.send
			expect(run.native.contentApprove).toHaveBeenCalledTimes(1)
			expect(run.native.contentCommit).toHaveBeenCalledTimes(name === 'nc_forms_create_form' ? 5 : 1)
			expect(vi.mocked(run.native.contentCommit!).mock.calls.map(c => c[2])).toEqual(name === 'nc_forms_create_form' ? [0, 1, 2, 3, 4] : [0])
			expect(run.workspace.state.latest).toMatchObject({ outcome: 'committed', view: 'refreshed' })
		} finally { run.agent.stop(); await run.send; await run.agent.dispose() }
	})
	it.each(['deny', 'preview-tamper', 'stop-before-allow'])('dispatches no content after %s', async decision => {
		const run = await flow(specs[4][0], specs[4][1])
		try {
			const part = await pending(run.agent)
			if (decision === 'deny') await run.agent.deny(part)
			else if (decision === 'stop-before-allow') run.agent.stop()
			else { part.request.arguments.nativeAudience = 'public'; await expect(run.agent.approve(part)).rejects.toThrow(); await run.agent.deny(part) }
			await run.send; expect(run.native.contentCommit).not.toHaveBeenCalled(); expect(run.native.contentCancel).toHaveBeenCalled()
		} finally { run.agent.stop(); await run.agent.dispose() }
	})
	it.each(['lost-ack', 'stop'])('does not repeat or continue a Forms build after %s', async option => {
		const run = await flow(specs[2][0], specs[2][1], option)
		try { await run.agent.approve(await pending(run.agent)); await run.send.catch(() => {}); expect(run.native.contentCommit).toHaveBeenCalledTimes(1); expect(run.native.contentCancel).toHaveBeenCalled() }
		finally { run.agent.stop(); await run.agent.dispose() }
	})
	it('keeps native submission acceptance distinct from verified stored answers', async () => {
		const run = await flow(specs[3][0], specs[3][1], 'accepted-unknown')
		try { await run.agent.approve(await pending(run.agent)); await run.send; expect(run.native.contentCommit).toHaveBeenCalledTimes(1); expect(run.workspace.state.latest?.outcome).toBe('unknown_completion'); expect(run.agent.chat.turns.flatMap(t => [...t.parts]).map(p => (p as unknown as { text?: string }).text ?? '').join(' ')).toContain('Do not submit it again') }
		finally { run.agent.stop(); await run.agent.dispose() }
	})
	it('never offers a content provider missing from native capability discovery', async () => {
		const run = await flow(specs[5][0], specs[5][1], 'unavailable')
		try { await run.send.catch(() => {}); expect(run.native.contentPropose).not.toHaveBeenCalled(); expect(run.native.contentCommit).not.toHaveBeenCalled() }
		finally { run.agent.stop(); await run.agent.dispose() }
	})
})
