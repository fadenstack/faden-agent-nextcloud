import { describe, expect, it, vi } from 'vitest'
import { FakeGateway } from '@fadenstack/testing'
import { ApprovalPart } from '@fadenstack/ui'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { HISTORY_CAPABILITIES, FILE_CAPABILITIES } from '../../src/platform/catalog.ts'
import type { FileApi, HistoryPreview, Identity } from '../../src/integrations/nextcloud/types.ts'

const action = 'a'.repeat(32)
const preview: HistoryPreview = { id: 'b'.repeat(48), digest: 'c'.repeat(64), actionId: action, direction: 'undo',
	target: '/draft.md', before: 'Generated content', content: 'Original content', expires: 9999999999 }
function fixture(historyEnabled = true) {
	let round = 0
	const gateway = new FakeGateway({ agents: [{ slug: 'nc', version: 1, contract: [{ name: 'nc_actions_undo', risk: 'destructive' }, { name: 'nc_actions_redo', risk: 'destructive' }] }],
		users: [{ email: 'alice', password: 'test', agents: ['nc'] }],
		model: () => ++round === 1 ? { toolCalls: [{ id: 'inverse', name: 'nc_actions_undo', arguments: { action_id: action } }] } : { text: 'Finished' } })
	const identity: Identity = { userId: 'alice', binding: 'native-binding', enabled: true, serverUrl: gateway.serverUrl, agent: 'nc', development: false, historyEnabled }
	const file = { id: 2, name: 'draft.md', path: '/draft.md', mime: 'text/markdown', size: 16, etag: 'revision', folder: false, permissions: 31, url: 'https://cloud.test/f/2' }
	const files: FileApi = {
		identity: vi.fn(async () => identity), assertIdentity: vi.fn(async () => {}), setCloudIdentity: vi.fn(),
		select: vi.fn(), read: vi.fn(), pdf: vi.fn(), list: vi.fn(), propose: vi.fn(), approve: vi.fn(), commit: vi.fn(),
		history: {
			list: vi.fn(async () => []), prepare: vi.fn(async () => preview), approve: vi.fn(async () => {}), cancel: vi.fn(async () => {}),
			execute: vi.fn(async () => ({ operation: 'undo', state: 'undone', actionId: action, file })),
		},
	}
	const host = new NextcloudAgent(identity, files, gateway.fetch)
	return { host, files, gateway }
}
async function pending(host: NextcloudAgent): Promise<ApprovalPart> {
	let part!: ApprovalPart
	await vi.waitFor(() => {
		part = host.chat.turns.flatMap(turn => [...turn.parts]).find(value => value instanceof ApprovalPart && value.isPending) as ApprovalPart
		expect(part).toBeDefined()
	})
	return part
}

describe('governed history integration', () => {
	it('retains the legacy nine tools and requires individual approval for all history effects', () => {
		expect(FILE_CAPABILITIES).toHaveLength(9)
		expect(HISTORY_CAPABILITIES.map(value => [value.name, value.risk, value.individualApproval])).toEqual([
			['nc_actions_undo', 'destructive', true], ['nc_actions_redo', 'destructive', true],
			['nc_actions_reconcile', 'destructive', true],
		])
	})
	it('does not register history tools when the administrator has not enabled them', async () => {
		const { host, files } = fixture(false)
		await host.signIn('alice', 'test')
		expect(host.historyAvailable).toBe(false)
		expect(host.agent.find('nc_actions_undo')).toBeUndefined()
		expect(files.setCloudIdentity).not.toHaveBeenCalled()
		await host.dispose()
	})
	it('requires an exact native preview and native approval before the SDK dispatches an inverse', async () => {
		const { host, files } = fixture()
		await host.signIn('alice', 'test')
		expect(files.setCloudIdentity).toHaveBeenCalledWith(expect.any(Function))
		const sending = host.send('Undo the recorded file change', false)
		const part = await pending(host)
		expect(part.request.arguments).toMatchObject({ action_id: action, historyPreviewId: preview.id, digest: preview.digest, target: preview.target, before: preview.before, content: preview.content })
		expect(part.request.canAllowAlways).toBe(false)
		expect(part.request.canAllowForSession).toBe(false)
		expect(files.history!.execute).not.toHaveBeenCalled()
		await host.approve(part)
		await sending
		expect(files.history!.approve).toHaveBeenCalledWith(preview, expect.any(AbortSignal))
		expect(files.history!.execute).toHaveBeenCalledWith(preview, expect.any(AbortSignal))
		expect(files.commit).not.toHaveBeenCalled()
		await host.dispose()
	})
	it.each(['deny', 'stop'] as const)('%s during approval never dispatches and releases the unused preview', async answer => {
		const { host, files } = fixture()
		await host.signIn('alice', 'test')
		const sending = host.send('Undo the action', false)
		const part = await pending(host)
		if (answer === 'deny') part.deny()
		else host.stop()
		await sending
		expect(files.history!.approve).not.toHaveBeenCalled()
		expect(files.history!.execute).not.toHaveBeenCalled()
		expect(files.history!.cancel).toHaveBeenCalledWith(preview, expect.any(AbortSignal))
		expect(host.chat.isBusy).toBe(false)
		await host.dispose()
	})
	it('refuses substituted action or direction even with a pending approved-preview call ID', async () => {
		const { host, files } = fixture()
		await host.signIn('alice', 'test')
		const sending = host.send('Undo the action', false)
		const part = await pending(host)
		const call = { callId: part.request.callId, signal: new AbortController().signal } as Parameters<NextcloudAgent['reverse']>[2]
		await expect(host.reverse('redo', action, call)).rejects.toThrow('No matching')
		await expect(host.reverse('undo', 'd'.repeat(32), call)).rejects.toThrow('No matching')
		expect(files.history!.execute).not.toHaveBeenCalled()
		part.deny()
		await sending
		await host.dispose()
	})
	it.skipIf(!!process.env.FADENSTACK_COMMAND_SDK)('keeps explicit controls unavailable with the published SDK and has no direct execution fallback', async () => {
		const { host, files, gateway } = fixture()
		await host.signIn('alice', 'test')
		expect(host.historyCommandsAvailable).toBe(false)
		await expect(host.invokeHistory('undo', action)).rejects.toThrow('unavailable')
		expect(files.history!.prepare).not.toHaveBeenCalled()
		expect(gateway.modelCalls).toHaveLength(0)
		await host.dispose()
	})
})
