import { describe, expect, it, vi } from 'vitest'
import { ApprovalPart, ToolCallPart } from '@fadenstack/ui'
import { FakeGateway } from '@fadenstack/testing'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { WorkspaceCoordinator } from '../../src/workspace/coordinator.ts'
import type { FileApi, Identity } from '../../src/integrations/nextcloud/types.ts'
import type { CommunicationProposal, NativeProvidersApi } from '../../src/providers/client.ts'
import type { ProviderResource } from '../../src/providers/scope.ts'

const rev = 'a'.repeat(64)
const tools = ['nc_mail_read', 'nc_mail_save_draft', 'nc_mail_send_draft', 'nc_talk_read', 'nc_talk_send_message']
const identity: Identity = { userId: 'alice', binding: 'native-session', enabled: true, agent: 'communications', serverUrl: 'https://faden.test', development: true, providerReadsEnabled: true,
	scopePolicy: { version: 'test', gatewayOrigin: 'https://faden.test', sources: [{ kind: 'user-files' }], destinations: [], tools, sourceClasses: ['standard'], downstreamPolicy: 'unverified',
		destinationAudiences: ['unknown', 'shared'], movements: [{ source: 'standard', destination: 'unknown' }, { source: 'standard', destination: 'shared' }], developmentLoopback: true } }
async function flow(name: string, options: { unavailable?: boolean; unknown?: boolean; stopAfterFirst?: boolean; refused?: boolean } = {}) {
	const provider = name.startsWith('nc_mail') ? 'mail' as const : 'talk' as const
	const resource: ProviderResource = { provider, resourceId: `nextcloud:${provider}:1`, collectionId: provider === 'mail' ? '1' : 'abcd1234', revision: rev, policyVersion: 'native-policy', sourceClass: 'standard', audience: provider === 'mail' ? 'unknown' : 'shared', filename: provider === 'mail' ? '{"accountId":"1"}' : '{}' }
	const args = name === 'nc_mail_save_draft' ? { accountRef: '1', subject: 'Synthetic draft', body: 'Exact body', recipients: ['bob@example.invalid'] }
		: name === 'nc_mail_send_draft' ? { draftId: 7, expectedRevision: rev } : { roomToken: 'abcd1234', message: 'Exact message' }
	let step = 0, agent: NextcloudAgent
	const native: NativeProvidersApi = {
		capabilities: vi.fn(async () => options.unavailable ? [] : [{ provider, enabled: true, apiVersion: provider === 'mail' ? 'mail-5.13-api' : 'talk-25-v4-v1', policyVersion: 'native-policy', tools: tools.filter(t => t.startsWith(`nc_${provider}_`)), conflict: 'native', history: 'audit-only', refresh: 'child-reload', canRead: true, canWrite: true }]),
		calendarCollections: vi.fn(async () => []), calendarEvents: vi.fn(async () => []), calendarEvent: vi.fn(), calendarPropose: vi.fn(), calendarApprove: vi.fn(), calendarCommit: vi.fn(),
		communicationRead: vi.fn(async () => ({ resource, content: { revision: rev } })),
		communicationPropose: vi.fn(async (toolName, input): Promise<CommunicationProposal> => ({ id: 'b'.repeat(48), digest: 'c'.repeat(64), provider, toolName, target: 'Synthetic communication', payload: { ...input }, before: null, resource, expires: Date.now() / 1000 + 600, history: 'audit-only', limitation: 'Native last-write-wins; audit only, no Undo.', display: [] })),
		communicationApprove: vi.fn(async () => ({ approved: true })), communicationCancel: vi.fn(async () => ({})),
		communicationCommit: vi.fn(async () => {
			step++
			if (options.refused) throw Object.assign(new Error('Native permission revoked.'), { response: { status: 403, data: { outcome: 'not-dispatched', message: 'Native permission revoked.' } } })
			if (options.unknown) throw new Error('No native acknowledgement; inspect without resending.')
			if (options.stopAfterFirst && step === 1) agent.stop()
			const more = provider === 'mail' && step < (name === 'nc_mail_send_draft' ? 3 : 2)
			return { outcome: more ? 'running' : 'verified', continue: more, actionId: 'e'.repeat(32), provider, collectionId: resource.collectionId, target: 'Synthetic communication', mailboxId: 2, result: { draftId: 8 }, history: 'audit-only', limitation: 'Native last-write-wins.' }
		}),
	}
	const files: FileApi = { identity: async () => identity, assertIdentity: async () => {}, select: vi.fn(), read: vi.fn(), list: vi.fn(), pdf: vi.fn(), propose: vi.fn(), approve: vi.fn(), commit: vi.fn(), nativeProviders: native, scopeResource: vi.fn() }
	let round = 0
	const gateway = new FakeGateway({ serverUrl: identity.serverUrl, agents: [{ slug: identity.agent, version: 1, contract: tools.map(name => ({ name, risk: name.endsWith('_read') ? 'read' : 'destructive' })) }], users: [{ email: 'alice', password: 'test', agents: [identity.agent] }],
		model: () => ++round === 1 ? { toolCalls: [{ id: 'read', name: `nc_${provider}_read`, arguments: provider === 'mail' ? { accountId: '1' } : { roomToken: 'abcd1234' } }] }
			: round === 2 ? { toolCalls: [{ id: 'write', name, arguments: args }] } : { text: 'Finished.' } })
	const workspace = new WorkspaceCoordinator({ guard: { snapshot: () => ({ app: provider === 'mail' ? 'mail' : 'spreed', key: 'native/1', epoch: 1, editState: 'clean', reason: '' }), dispose: () => {} }, refresh: vi.fn(async () => 'refreshed') })
	agent = new NextcloudAgent(identity, files, gateway.fetch, workspace)
	await agent.signIn('alice', 'test')
	return { agent, native, workspace, send: agent.send('Perform this synthetic native operation', false) }
}
async function pending(agent: NextcloudAgent): Promise<ApprovalPart> {
	let part: ApprovalPart | undefined
	await vi.waitFor(() => {
		part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart
		expect(part?.isPending, JSON.stringify(agent.chat.turns.map(t => [...t.parts].map(p => ({ type: p.constructor.name, ...Object.fromEntries(['state', 'text', 'name', 'error'].map(k => [k, (p as unknown as Record<string, unknown>)[k]])) }))))).toBe(true)
	})
	return part!
}
describe('Native Mail/Talk through published SDK governance', () => {
	it.each(tools.filter(t => !t.endsWith('_read')))('commits the bounded native stages for %s with the general experimental gate off', async name => {
		const run = await flow(name)
		try {
			const part = await pending(run.agent)
			expect(run.native.communicationCommit).not.toHaveBeenCalled()
			expect(part.request).toMatchObject({ canAllowAlways: false, canAllowForSession: false })
			await run.agent.approve(part); await run.send
			expect(run.native.communicationApprove).toHaveBeenCalledTimes(1)
			expect(run.native.communicationCommit).toHaveBeenCalledTimes(name === 'nc_mail_save_draft' ? 2 : name === 'nc_mail_send_draft' ? 3 : 1)
			expect(vi.mocked(run.native.communicationCommit!).mock.calls.map(call => call[2])).toEqual(name === 'nc_mail_save_draft' ? [0, 1] : name === 'nc_mail_send_draft' ? [0, 1, 2] : [0])
			expect(run.workspace.state.latest).toMatchObject({ outcome: 'committed', view: 'refreshed' })
		} finally { run.agent.stop(); await run.agent.dispose() }
	})
	it.each(['deny', 'stop', 'payload-change', 'audience-change'])('dispatches nothing after %s', async decision => {
		const run = await flow('nc_mail_save_draft')
		try {
			const part = await pending(run.agent)
			if (decision === 'deny') await run.agent.deny(part)
			else if (decision === 'stop') run.agent.stop()
			else { part.request.arguments[decision === 'payload-change' ? 'content' : 'nativeAudience'] = 'tampered'; await expect(run.agent.approve(part)).rejects.toThrow('proposal changed'); await run.agent.deny(part) }
			await run.send; expect(run.native.communicationCommit).not.toHaveBeenCalled(); expect(run.native.communicationCancel).toHaveBeenCalled()
		} finally { run.agent.stop(); await run.agent.dispose() }
	})
	it.each([{ unknown: true }, { stopAfterFirst: true }])('never repeats an uncertain or stopped stage: %j', async options => {
		const run = await flow('nc_mail_send_draft', options)
		try { await run.agent.approve(await pending(run.agent)); await run.send.catch(() => {}); expect(run.native.communicationCommit).toHaveBeenCalledTimes(1); expect(run.native.communicationCancel).toHaveBeenCalled() }
		finally { run.agent.stop(); await run.agent.dispose() }
	})
	it('does not offer an unavailable native provider', async () => {
		const run = await flow('nc_talk_send_message', { unavailable: true })
		try { await run.send.catch(() => {}); expect(run.native.communicationPropose).not.toHaveBeenCalled(); expect(run.native.communicationCommit).not.toHaveBeenCalled() }
		finally { run.agent.stop(); await run.agent.dispose() }
	})
	it('keeps a native denial distinct from unknown completion', async () => {
		const run = await flow('nc_talk_send_message', { refused: true })
		try { await run.agent.approve(await pending(run.agent)); await run.send.catch(() => {}); expect(run.native.communicationCommit).toHaveBeenCalledTimes(1); expect(run.workspace.state.latest).toMatchObject({ outcome: 'not_dispatched', view: 'unchanged' }); expect(run.agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ToolCallPart && p.name === 'nc_talk_send_message')).toMatchObject({ state: 'failed' }) }
		finally { run.agent.stop(); await run.agent.dispose() }
	})
})
