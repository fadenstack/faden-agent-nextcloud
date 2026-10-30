import { describe, expect, it, vi } from 'vitest'
import { FakeGateway } from '@fadenstack/testing'
import { ApprovalPart, type UiStrings } from '@fadenstack/ui'
import { createApp } from 'vue'
import NextcloudApproval from '../../src/components/NextcloudApproval.vue'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import type { FileApi, FileContext, HistoryDirection, HistoryPreview, Identity } from '../../src/integrations/nextcloud/types.ts'
import type { WorkspaceCoordinator } from '../../src/workspace/coordinator.ts'

const action = 'a'.repeat(32)
const preview: HistoryPreview = { id: 'b'.repeat(48), digest: 'c'.repeat(64), actionId: action, direction: 'undo', target: '/draft.md', before: 'Generated content', content: 'Original content', expires: 9999999999 }
const context: FileContext = { id: 'context', parentId: 1, expires: 9999999999, file: { id: 2, name: 'draft.md', path: '/draft.md', mime: 'text/markdown', size: 16, etag: 'revision', folder: false, permissions: 31, url: 'https://cloud.test/f/2' } }
async function fixture(workspace?: WorkspaceCoordinator, direction: HistoryDirection = 'undo') {
	const gateway = new FakeGateway({ agents: [{ slug: 'nc', version: 1, contract: ['undo', 'redo', 'reconcile', 'revalidate'].map(value => ({ name: `nc_actions_${value}`, risk: 'destructive' as const })) }], users: [{ email: 'alice', password: 'test', agents: ['nc'] }] })
	const identity: Identity = { userId: 'alice', binding: 'native-binding', enabled: true, serverUrl: gateway.serverUrl, agent: 'nc', development: false, historyEnabled: true }
	const files: FileApi = {
		identity: vi.fn(async () => identity), assertIdentity: vi.fn(async () => {}), setCloudIdentity: vi.fn(), select: vi.fn(async () => context),
		read: vi.fn(), pdf: vi.fn(), list: vi.fn(), propose: vi.fn(), approve: vi.fn(), commit: vi.fn(),
		history: { list: vi.fn(async () => []), prepare: vi.fn(async () => direction === 'reconcile' ? { ...preview, direction, before: 'Observed file', content: 'Observed file', resolution: { outcome: 'verified' as const, state: 'applied' as const } } : direction === 'revalidate' ? { ...preview, direction, content: preview.before, revalidation: { state: 'applied' as const, recordedRevision: 'old-revision', currentRevision: 'restored-revision' } } : preview), approve: vi.fn(async () => {}), cancel: vi.fn(async () => {}), execute: vi.fn(async () => ({ operation: direction, state: ['reconcile', 'revalidate'].includes(direction) ? 'applied' : 'undone', actionId: action, file: context.file })) },
	}
	const host = new NextcloudAgent(identity, files, gateway.fetch, workspace)
	const resource = host.agent.client.agent!
	const getProfile = resource.getProfile.bind(resource)
	const principal = (agentId: string) => ({ tenantId: 'tenant-fixture', userId: 'cloud-a', agentId, deviceId: host.tokens.deviceId ?? 'device-fixture' })
	vi.spyOn(resource, 'getProfile').mockImplementation(async (...args) => {
		const profile = await getProfile(...args)
		return { ...profile, principal: principal(profile.id) }
	})
	// This is an SDK/host integration fixture. Native HTTP and real gateway
	// producer contracts are tested separately; no live issuer is claimed here.
	const policyClient = resource as typeof resource & { checkHostTool(check: Record<string, unknown>): Promise<Record<string, unknown>> }
	const decision = () => ({ decision: 'ask', toolClass: 'destructive', effectiveToolMode: 'auto', requiredByRule: false, message: 'Individual approval',
		profileStamp: 'd'.repeat(64), argumentsStamp: 'e'.repeat(64), decisionStamp: 'f'.repeat(64), principal: principal(host.agent.profile.id) })
	const check = vi.spyOn(policyClient, 'checkHostTool').mockImplementation(async () => decision())
	await host.signIn('alice', 'test')
	host.chat.selectToolMode('auto')
	return { host, files, gateway, check, decision }
}
async function pending(host: NextcloudAgent): Promise<ApprovalPart> {
	let part!: ApprovalPart
	await vi.waitFor(() => {
		part = host.chat.turns.flatMap(turn => [...turn.parts]).find(value => value instanceof ApprovalPart && value.isPending) as ApprovalPart
		expect(part).toBeDefined()
	})
	return part
}

describe('published SDK explicit native history contract', () => {
	it.each(['approve', 'stop'] as const)('verifies restored revision through individual approval; %s preserves the settled state without replay', async answer => {
		const { host, files, gateway, check } = await fixture(undefined, 'revalidate')
		host.history.items = [{ id: action, name: 'draft.md', state: 'applied', eligible: 'revalidate', version: 3, createdAt: 1 }]
		const running = host.invokeHistory('revalidate', action)
		const part = await pending(host)
		expect(part.request.arguments).toMatchObject({ action_id: action, before: preview.before, content: preview.before, revalidation: { state: 'applied', recordedRevision: 'old-revision', currentRevision: 'restored-revision' } })
		expect(part.request.canAllowAlways).toBe(false)
		expect(part.request.canAllowForSession).toBe(false)
		expect(files.history!.execute).not.toHaveBeenCalled()
		if (answer === 'approve') await host.approve(part)
		else host.stop()
		await running
		expect(files.history!.execute).toHaveBeenCalledTimes(answer === 'approve' ? 1 : 0)
		expect(host.history.items[0]).toMatchObject({ state: 'applied', eligible: answer === 'approve' ? 'undo' : 'revalidate' })
		expect(files.propose).not.toHaveBeenCalled()
		expect(files.commit).not.toHaveBeenCalled()
		expect(gateway.modelCalls).toHaveLength(0)
		expect(check.mock.calls.map(([request]) => [request.name, request.phase])).toEqual(answer === 'approve' ? [['nc_actions_revalidate', 'prepare'], ['nc_actions_revalidate', 'dispatch']] : [['nc_actions_revalidate', 'prepare']])
		await host.dispose()
	})
	it('governs inspection with exact observed content, individual approval and no replayed file write', async () => {
		const { host, files, gateway, check } = await fixture(undefined, 'reconcile')
		host.history.items = [{ id: action, name: 'draft.md', state: 'needs_reconciliation', eligible: 'reconcile', version: 3, createdAt: 1 }]
		host.chat.setInput('Keep this draft')
		const running = host.invokeHistory('reconcile', action)
		const part = await pending(host)
		expect(part.request.arguments).toMatchObject({ action_id: action, content: 'Observed file', resolution: { outcome: 'verified', state: 'applied' } })
		expect(part.request.canAllowAlways).toBe(false)
		expect(files.history!.execute).not.toHaveBeenCalled()
		await host.approve(part)
		await running
		expect(host.chat.turns[0].userText).toBe('Inspect outcome')
		expect(host.chat.input).toBe('Keep this draft')
		expect(host.history.items[0]).toMatchObject({ state: 'applied', eligible: 'undo' })
		expect(check.mock.calls.map(([request]) => [request.name, request.phase])).toEqual([['nc_actions_reconcile', 'prepare'], ['nc_actions_reconcile', 'dispatch']])
		expect(files.propose).not.toHaveBeenCalled()
		expect(files.commit).not.toHaveBeenCalled()
		expect(gateway.modelCalls).toHaveLength(0)
		await host.dispose()
	})
	it('does not offer Undo after inspecting an unapplied original attempt', async () => {
		const { host, files } = await fixture(undefined, 'reconcile')
		host.history.items = [{ id: action, name: 'draft.md', state: 'needs_reconciliation', eligible: 'reconcile', version: 3, createdAt: 1 }]
		vi.mocked(files.history!.execute).mockResolvedValue({ operation: 'reconcile', state: 'failed', file: context.file })
		const running = host.invokeHistory('reconcile', action)
		await host.approve(await pending(host))
		await running
		expect(host.history.items[0]).toMatchObject({ state: 'failed', eligible: 'none' })
		await host.dispose()
	})
	it('shares exact approval and busy state, preserves composer/attachment, checks twice and avoids model replay', async () => {
		const { host, files, gateway, check } = await fixture()
		host.chat.setInput('Keep this unsent draft')
		host.offerSelection(await host.selection.select(2))
		const attachment = host.contextAttachment
		const running = host.invokeHistory('undo', action)
		const part = await pending(host)
		expect(host.chat.isBusy).toBe(true)
		expect(part.request.arguments).toMatchObject({ target: preview.target, before: preview.before, content: preview.content })
		expect(files.read).not.toHaveBeenCalled()
		expect(files.history!.execute).not.toHaveBeenCalled()
		await host.approve(part)
		await running
		expect(files.history!.execute).toHaveBeenCalledOnce()
		expect(host.chat.turns[0].userText).toBe('Undo file change')
		expect(host.chat.isBusy).toBe(false)
		expect(host.chat.input).toBe('Keep this unsent draft')
		expect(host.contextAttachment).toBe(attachment)
		expect(check.mock.calls.map(([request]) => request.phase)).toEqual(['prepare', 'dispatch'])
		expect(check.mock.calls[0][0].schemaSha256).toBe('7e36e734beb61c0278907d2bd106c0c92ca23732196553e4b31978bb9760541b')
		expect(gateway.modelCalls).toHaveLength(0)
		await host.send('Separate ordinary message', false)
		expect(gateway.modelCalls).toHaveLength(1)
		expect(gateway.modelCalls[0].messages.some(message => message.role === 'tool')).toBe(false)
		await host.dispose()
	})
	it('the same Stop cancels a pending explicit approval and clears the unused native preview', async () => {
		const { host, files, gateway } = await fixture()
		const running = host.invokeHistory('undo', action)
		const part = await pending(host)
		const root = document.createElement('div')
		document.body.append(root)
		const app = createApp(NextcloudApproval, { host, part, strings: { allow: 'Allow', deny: 'Deny' } as UiStrings })
		app.mount(root)
		expect(root.querySelector('.fdn-approval-head strong')?.textContent).toBe('Undo file change')
		host.stop()
		await running
		try {
			await vi.waitFor(() => expect(root.querySelector('.fdn-approval-state')?.textContent?.trim()).toBe('Stopped'))
			expect(root.textContent).not.toContain('No answer in time')
		} finally { app.unmount(); root.remove() }
		expect(files.history!.approve).not.toHaveBeenCalled()
		expect(files.history!.execute).not.toHaveBeenCalled()
		expect(files.history!.cancel).toHaveBeenCalledWith(preview, expect.any(AbortSignal))
		expect(host.chat.isBusy).toBe(false)
		expect(gateway.modelCalls).toHaveLength(0)
		await host.dispose()
	})
	it('dirty native view preparation refuses the command before central checks or preview', async () => {
		const workspace = { prepare: vi.fn(() => { throw new Error('Save your native edits first.') }) } as unknown as WorkspaceCoordinator
		const { host, files, check } = await fixture(workspace)
		host.chat.setInput('Unsent draft')
		await host.invokeHistory('undo', action)
		expect(host.chat.error).toBe('Save your native edits first.')
		expect(host.chat.input).toBe('Unsent draft')
		expect(check).not.toHaveBeenCalled()
		expect(files.history!.prepare).not.toHaveBeenCalled()
		await host.dispose()
	})
	it('central deny blocks native preview and never becomes a model request', async () => {
		const { host, files, gateway, check, decision } = await fixture()
		check.mockImplementation(async () => ({ ...decision(), decision: 'deny' }))
		await host.invokeHistory('undo', action)
		expect(files.history!.prepare).not.toHaveBeenCalled()
		expect(files.history!.execute).not.toHaveBeenCalled()
		expect(gateway.modelCalls).toHaveLength(0)
		await host.dispose()
	})
	it('Stop during native preview preparation aborts the borrowed command signal without dispatch', async () => {
		const { host, files } = await fixture()
		let preparing = false
		files.history!.prepare = vi.fn(async (_id, _direction, signal) => {
			preparing = true
			return new Promise((_resolve, reject) => { signal.addEventListener('abort', () => reject(signal.reason), { once: true }) })
		})
		const running = host.invokeHistory('undo', action)
		await vi.waitFor(() => expect(preparing).toBe(true))
		host.stop()
		await running
		expect(files.history!.execute).not.toHaveBeenCalled()
		expect(host.chat.isBusy).toBe(false)
		await host.dispose()
	})
})
