import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApprovalPart } from '@fadenstack/ui'
import { FakeGateway } from '../helpers/gateway071.ts'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { EngineSurface } from '../../src/workspace/engineSurface.ts'
import { WorkspaceCoordinator } from '../../src/workspace/coordinator.ts'
import { nativeProvidersApi, type ContentProposal, type NativeProvidersApi } from '../../src/providers/client.ts'
import type { FileApi, Identity } from '../../src/integrations/nextcloud/types.ts'

const reference = 'u/alice/source'
const revision = 'd'.repeat(64)
const binding = { reference, kind: 'saved' as const, revision }
const globalWindow = window as unknown as { __fadenstackEngineSurface?: EngineSurface }
afterEach(() => { delete globalWindow.__fadenstackEngineSurface })

async function fixture(mode = 'summary') {
	const surface = new EngineSurface({ connectionId: 'own', provider: 'windmill', label: 'Own Windmill', url: 'https://engine.test/?workspace=own', origin: 'https://engine.test', revision: 'a'.repeat(64) })
	globalWindow.__fadenstackEngineSurface = surface
	const identity: Identity = { userId: 'alice', binding: 'native-session', enabled: true, agent: 'workspace-runtime', serverUrl: 'https://faden.test', development: true, providerReadsEnabled: true,
		scopePolicy: { version: 'test', gatewayOrigin: 'https://faden.test', sources: [{ kind: 'user-files' }], destinations: [], tools: ['nc_windmill_read', 'nc_windmill_draft'], sourceClasses: ['standard'], downstreamPolicy: 'unverified', destinationAudiences: ['private'], movements: [{ source: 'standard', destination: 'private' }], developmentLoopback: true } }
	const resource = { provider: 'windmill' as const, collectionId: 'own', resourceId: 'nextcloud:windmill:c291cmNl', revision, policyVersion: 'native-policy', sourceClass: 'standard' as const, audience: 'private' as const, filename: JSON.stringify({ connectionId: 'own', reference }) }
	const args = { connectionId: 'own', definitionJson: '{"summary":"Copy"}', sourceReference: reference, sourceRevision: revision, sourceKind: 'saved' }
	if (mode === 'wrong-connection') args.connectionId = 'other'
	if (mode === 'wrong-source') args.sourceReference = 'u/alice/other'
	if (mode === 'wrong-revision') args.sourceRevision = 'e'.repeat(64)
	const native: NativeProvidersApi = {
		calendarCollections: vi.fn(async () => []), calendarEvents: vi.fn(async () => []),
		capabilities: vi.fn(async () => [{ provider: 'windmill', apiVersion: 'windmill-1.841.0-token', policyVersion: 'native-policy', enabled: true, tools: mode === 'no-reader' ? [] : ['nc_windmill_read', 'nc_windmill_draft'], canRead: true, canWrite: true, history: 'audit-only', conflict: 'native', refresh: 'manual' }]),
		contentRead: vi.fn(async () => ({ workspaceRevision: surface.descriptor.revision, provider: 'windmill', connectionId: 'own', reference, copySource: binding, definition: { path: reference, summary: 'Native source', value: { modules: [] } }, resource })),
		contentPropose: vi.fn(async (): Promise<ContentProposal> => ({ workspaceRevision: surface.descriptor.revision, id: 'a'.repeat(48), digest: 'b'.repeat(64), toolName: 'nc_windmill_draft', provider: 'windmill', target: 'New copy', before: null, payload: { definitionJson: '{}' }, copySource: binding, resource, history: 'audit-only', limitation: 'Audit only; no Undo', expires: Date.now() / 1000 + 600, display: [] })),
		contentApprove: vi.fn(async () => ({ approved: true })), contentCancel: vi.fn(async () => ({})),
		contentCommit: vi.fn(async () => ({ provider: 'windmill', target: 'New copy', collectionId: 'own', history: 'audit-only', outcome: 'verified', continue: false, actionId: 'c'.repeat(32), result: { reference: 'u/alice/new_copy', inactiveDraft: true, originStored: true } })),
	}
	surface.setApi(native)
	await surface.selectTarget(reference)
	surface.pause()
	const workspace = new WorkspaceCoordinator({ guard: { snapshot: () => surface.snapshot(), dispose() {} }, refresh: (r, s) => surface.refresh(r, s) })
	const files: FileApi = { identity: async () => identity, assertIdentity: async () => {}, scopeResource: vi.fn(), select: vi.fn(), read: vi.fn(), list: vi.fn(), pdf: vi.fn(), propose: vi.fn(), approve: vi.fn(), commit: vi.fn(), nativeProviders: native }
	let messages = '', modelCalls = 0
	const gateway = new FakeGateway({ agents: [{ slug: identity.agent, version: 1, contract: identity.scopePolicy!.tools.map(name => ({ name, risk: name.endsWith('_read') ? 'read' : 'destructive' })) }], users: [{ email: 'alice', password: 'test', agents: [identity.agent] }], model: request => {
		messages = JSON.stringify(request.messages)
		modelCalls++
		if (mode === 'audit' || mode === 'discovery') return modelCalls === 1
			? { toolCalls: [{ id: 'read', name: 'nc_windmill_read', arguments: mode === 'audit' ? { actionId: 'c'.repeat(32) } : {} }] }
			: { text: 'Read-only result.' }
		return mode === 'summary' || mode === 'no-reader' ? { text: 'Summary.' } : { toolCalls: [{ id: 'copy', name: 'nc_windmill_draft', arguments: args }] }
	} })
	const host = new NextcloudAgent(identity, files, gateway.fetch, workspace)
	await host.signIn('alice', 'test')
	surface.bind(host.chat, native)
	return { surface, native, host, get messages() { return messages }, send: () => host.send('Summarize or copy this selected workflow.', false), close: async () => { host.stop(); await host.dispose(); surface.unbind() } }
}

async function pending(host: NextcloudAgent): Promise<ApprovalPart> {
	let part: ApprovalPart | undefined
	await vi.waitFor(() => { part = host.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart; expect(part?.isPending).toBe(true) })
	return part!
}

describe('Governed workflow workspace through published SDK', () => {
	it.each(['audit', 'discovery'])('preserves bound %s reads in the workspace', async mode => {
		const test = await fixture(mode)
		test.surface.setTarget(undefined)
		try {
			await test.send()
			expect(test.native.contentRead).toHaveBeenCalledWith('windmill', mode === 'audit' ? { actionId: 'c'.repeat(32) } : { connectionId: 'own' }, expect.any(AbortSignal))
			expect(test.surface.state.connectionError).toBeUndefined()
			expect(test.native.contentCommit).not.toHaveBeenCalled()
		} finally { await test.close() }
	})
	it('retains standalone history revalidation without a configured browser workspace', async () => {
		const test = await fixture()
		try {
			await test.send()
			delete globalWindow.__fadenstackEngineSurface
			const original = await test.native.contentRead!('windmill', {}, new AbortController().signal)
			const { workspaceRevision: _binding, ...standalone } = original
			vi.mocked(test.native.contentRead!).mockResolvedValue(standalone)
			await expect(test.send()).resolves.toBeUndefined()
			expect(test.messages).toContain('Native source')
		} finally { await test.close() }
	})
	it.each([undefined, null, 'f'.repeat(64)])('revalidates workspace binding of stored model context (%s)', async workspaceRevision => {
		const test = await fixture()
		try {
			await test.send()
			test.surface.setTarget(undefined)
			const original = await test.native.contentRead!('windmill', {}, new AbortController().signal)
			vi.mocked(test.native.contentRead!).mockResolvedValue({ ...original, workspaceRevision } as never)
			const requests = test.messages
			await expect(test.send()).rejects.toThrow('workflow connection or account changed')
			expect(test.messages).toBe(requests)
		} finally { await test.close() }
	})
	it.each([undefined, null, '', 42, 'f'.repeat(64)])('blocks invalid workspace binding %s before model disclosure', async workspaceRevision => {
		const test = await fixture()
		const original = await test.native.contentRead!('windmill', {}, new AbortController().signal)
		vi.mocked(test.native.contentRead!).mockResolvedValue({ ...original, workspaceRevision } as never)
		try {
			await expect(test.send()).rejects.toThrow('workflow connection or account changed')
			expect(test.messages).toBe('')
			expect(test.native.contentCommit).not.toHaveBeenCalled()
		} finally { await test.close() }
	})
	it.each([undefined, '', 'f'.repeat(64)])('cancels proposals lacking the workspace binding %s', async workspaceRevision => {
		const test = await fixture('copy')
		const original = await test.native.contentPropose!('nc_windmill_draft', {}, new AbortController().signal)
		vi.mocked(test.native.contentPropose!).mockResolvedValue({ ...original, workspaceRevision })
		try {
			await test.send()
			expect(test.native.contentCancel).toHaveBeenCalledOnce()
			expect(test.native.contentApprove).not.toHaveBeenCalled()
			expect(test.native.contentCommit).not.toHaveBeenCalled()
		} finally { await test.close() }
	})
	it('sends freshly authorized, bounded native target context to the model', async () => {
		const test = await fixture()
		try {
			await test.send()
			expect(test.messages).toContain(reference)
			expect(test.messages).toContain('untrustedDocumentData')
			expect(test.messages).toContain(revision)
			expect(test.native.contentRead).toHaveBeenCalledWith('windmill', { connectionId: 'own', reference, sourceKind: 'saved' }, expect.any(AbortSignal))
		} finally { await test.close() }
	})
	it('retains the real approved source/copy distinction and attempts one save after Allow', async () => {
		const test = await fixture('copy')
		try {
			const send = test.send(), approval = await pending(test.host)
			expect(test.native.contentCommit).not.toHaveBeenCalled()
			await test.host.approve(approval); await send
			expect(test.native.contentCommit).toHaveBeenCalledTimes(1)
			expect(test.surface.state.lastResult).toMatchObject({ sourceReference: reference, sourceKind: 'saved', isCopy: true, createdReference: 'u/alice/new_copy', connectionId: 'own', actionId: 'c'.repeat(32) })
		} finally { await test.close() }
	})
	it('does not save after Deny', async () => {
		const test = await fixture('copy')
		try { const send = test.send(); await test.host.deny(await pending(test.host)); await send; expect(test.native.contentCommit).not.toHaveBeenCalled(); expect(test.surface.state.lastResult).toBeUndefined() } finally { await test.close() }
	})
	it.each(['wrong-connection', 'wrong-source', 'wrong-revision'])('refuses %s before native proposal', async mode => {
		const test = await fixture(mode)
		try { await test.send(); expect(test.native.contentPropose).not.toHaveBeenCalled(); expect(test.native.contentCommit).not.toHaveBeenCalled() } finally { await test.close() }
	})
	it('refuses a selected target without current published native read capability', async () => {
		const test = await fixture('no-reader')
		try { await expect(test.send()).rejects.toThrow('governed native reader'); expect(test.messages).toBe('') } finally { await test.close() }
	})
	it('refuses a changed source before any model disclosure', async () => {
		const test = await fixture()
		vi.mocked(test.native.contentRead!).mockResolvedValue({ workspaceRevision: test.surface.descriptor.revision, copySource: { ...binding, revision: 'e'.repeat(64) }, resource: { provider: 'windmill', collectionId: 'own', resourceId: 'nextcloud:windmill:c291cmNl', revision: 'e'.repeat(64), policyVersion: 'native-policy', sourceClass: 'standard', audience: 'private' } })
		try { await expect(test.send()).rejects.toThrow('selected workflow changed'); expect(test.messages).toBe('') } finally { await test.close() }
	})
	it.each(['missing', 'mismatch', 'valid'])('validates %s source metadata in the actual proposal transport', async mode => {
		const post = vi.fn(async () => ({}))
		const authenticated = vi.fn(async (_path, body: { requestId: string }) => ({ id: body.requestId, digest: 'b'.repeat(64), toolName: 'nc_windmill_draft', provider: 'windmill', target: 'Copy', payload: { definitionJson: '{}' }, resource: {}, history: 'audit-only', display: [], limitation: 'Audit only', destinationAudience: 'private', ...(mode === 'missing' ? {} : { copySource: { ...binding, reference: mode === 'mismatch' ? 'u/alice/other' : reference } }) }))
		const api = nativeProvidersApi(post as Parameters<typeof nativeProvidersApi>[0], authenticated as Parameters<typeof nativeProvidersApi>[1])
		const pending = api.contentPropose!('nc_windmill_draft', { connectionId: 'own', definitionJson: '{}', sourceReference: reference, sourceRevision: revision, sourceKind: 'saved' }, new AbortController().signal)
		if (mode === 'valid') { expect((await pending).copySource).toEqual(binding); expect(post).not.toHaveBeenCalled() }
		else { await expect(pending).rejects.toThrow(); expect(post).toHaveBeenCalledOnce() }
	})
	it('invalidates the workspace target and refuses model disclosure when the connection changes in another tab', async () => {
		const test = await fixture()
		vi.mocked(test.native.contentRead!).mockResolvedValue({
			provider: 'windmill',
			connectionId: 'own',
			reference,
			copySource: binding,
			workspaceRevision: 'f'.repeat(64),
			resource: { provider: 'windmill', collectionId: 'own', resourceId: 'nextcloud:windmill:c291cmNl', revision, policyVersion: 'native-policy', sourceClass: 'standard', audience: 'private' },
		} as never)
		try {
			await expect(test.send()).rejects.toThrow('workflow connection or account changed')
			expect(test.messages).toBe('')
			expect(test.surface.state.target).toBeUndefined()
			expect(test.surface.state.connectionError).toContain('Reload the native workspace')
		} finally { await test.close() }
	})
})
