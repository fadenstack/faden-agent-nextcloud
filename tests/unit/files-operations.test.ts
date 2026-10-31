import { describe, expect, it, vi } from 'vitest'
import { ApprovalPart, ToolCallPart } from '@fadenstack/ui'
import { FakeGateway } from '@fadenstack/testing'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { fileOperationsApi } from '../../src/integrations/nextcloud/fileOperations.ts'
import { filesMutationTools } from '../../src/integrations/nextcloud/filesExtended.ts'
import { WorkspaceCoordinator } from '../../src/workspace/coordinator.ts'
import type { FileApi, FileContext, FileOperationProposal, Identity } from '../../src/integrations/nextcloud/types.ts'
import type { ScopePolicy } from '../../src/platform/scope.ts'

const names = ['nc_files_copy', 'nc_files_move', 'nc_files_share_readonly']
const folder: FileContext = { id: 'folder-context', parentId: 50, expires: 9999999999,
	file: { id: 50, name: 'Project', path: '/Project', folder: true, mime: 'httpd/unix-directory', size: 0, etag: 'folder-etag', permissions: 31, url: 'http://localhost/f/50' } }
const source = { ...folder.file, id: 51, name: 'brief.md', path: '/Project/brief.md', folder: false, mime: 'text/markdown', size: 20, etag: 'source-etag' }
const scope: ScopePolicy = { version: 'files-ops-test', gatewayOrigin: 'https://faden.test', sources: [{ kind: 'user-files' }], destinations: [{ kind: 'user-files' }],
	tools: ['nc_list_folder', ...names], sourceClasses: ['standard'], downstreamPolicy: 'unverified', destinationAudiences: ['private', 'shared'],
	movements: [{ source: 'standard', destination: 'private' }, { source: 'standard', destination: 'shared' }], developmentLoopback: true }
const identity: Identity = { userId: 'alice', binding: 'native-binding', serverUrl: 'https://faden.test', agent: 'files-test', development: true, enabled: true, filesMutationsEnabled: true, scopePolicy: scope }
const proposal = (operation: FileOperationProposal['operation']): FileOperationProposal => ({
	id: 'a'.repeat(48), digest: 'b'.repeat(64), toolName: 'nc_files_' + operation, operation, sourceResourceId: 'nextcloud:files:51', sourcePath: source.path,
	destinationResourceId: 'nextcloud:files:50', destinationName: 'copy.md', recipientUserId: operation === 'share_readonly' ? 'bob' : '', expectedEtag: source.etag,
	target: operation === 'share_readonly' ? '/Project/brief.md → bob' : '/Project/copy.md', expires: Math.floor(Date.now() / 1000) + 600, history: 'audit-only', limitation: 'Native limitations; no Undo.',
})

async function flow(name: string, { enabled = true, capabilities = names, restricted = false } = {}) {
	const operation = name.slice(9) as FileOperationProposal['operation']
	const args = name.endsWith('share_readonly')
		? { resource_id: 'nextcloud:files:51', recipient_user_id: 'bob', expected_etag: source.etag }
		: { source_resource_id: 'nextcloud:files:51', destination_resource_id: 'nextcloud:files:50', destination_name: 'copy.md', expected_etag: source.etag }
	const native = {
		capabilities: vi.fn(async () => ({ tools: capabilities })), propose: vi.fn(async () => proposal(operation)), approve: vi.fn(async () => {}),
		commit: vi.fn(async () => ({ outcome: 'verified' as const, history: 'audit-only' as const, actionId: 'c'.repeat(32), parentResourceId: 'nextcloud:files:50', ...(restricted ? { disclosure: 'restricted' as const } : { file: { ...source, name: 'copy.md' } }) })),
		cancel: vi.fn(async () => {}),
	}
	const files: FileApi = {
		identity: async () => identity, assertIdentity: async () => {}, select: async () => folder,
		read: async () => ({ file: source, text: '', partial: false, totalCharacters: 0, processedCharacters: 0 }), pdf: async () => new Uint8Array(),
		list: async () => ({ folder: folder.file, files: [source], partial: false }),
		propose: vi.fn(), approve: vi.fn(), commit: vi.fn(), fileOperations: native,
		scopeResource: async resourceId => ({ resourceId, revision: resourceId.endsWith(':50') ? folder.file.etag : source.etag, sourceClass: 'standard', audience: 'private', scope: { kind: 'user-files' } }),
	}
	let round = 0
	const gateway = new FakeGateway({ agents: [{ slug: identity.agent, version: 1, contract: scope.tools.map(tool => ({ name: tool, risk: tool === 'nc_list_folder' ? 'read' as const : 'destructive' as const })) }],
		users: [{ email: 'alice', password: 'test', agents: [identity.agent] }], model: () => ++round === 1
			? { toolCalls: [{ id: 'list', name: 'nc_list_folder', arguments: {} }] }
			: round === 2 ? { toolCalls: [{ id: 'effect', name, arguments: args }] } : { text: 'Saved.' } })
	const workspace = new WorkspaceCoordinator({ guard: { snapshot: () => ({ app: 'files', key: 'files', epoch: 1, editState: 'clean', reason: '' }), dispose: () => {} },
		prepareProvider: vi.fn(async () => {}), refresh: vi.fn(async result => result.provider ? 'refreshed' : 'refresh_required') })
	const agent = new NextcloudAgent({ ...identity, filesMutationsEnabled: enabled }, files, gateway.fetch, workspace)
	await agent.signIn('alice', 'test')
	agent.offerSelection(await agent.selection.select(50))
	return { agent, native, workspace, send: agent.send('Organize the included project', true) }
}
async function pending(agent: NextcloudAgent) {
	let part: ApprovalPart | undefined
	await vi.waitFor(() => { part = agent.chat.turns.flatMap(turn => [...turn.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart; expect(part?.isPending).toBe(true) })
	return part!
}

describe('Typed native Files organization through the published SDK', () => {
	it.each(names)('advertises only arguments accepted by the native executor: %s', name => {
		const tool = filesMutationTools({ files: { fileOperations: {} } } as never).find(tool => tool.name === name)!
		const fields = name === 'nc_files_share_readonly' ? ['resource_id', 'recipient_user_id', 'expected_etag'] : ['source_resource_id', 'destination_resource_id', 'destination_name', 'expected_etag']
		expect(Object.keys(tool.parameters.properties!)).toEqual(expect.arrayContaining(fields))
		expect(Object.keys(tool.parameters.properties!)).toHaveLength(fields.length)
		expect(tool.parameters.required).toEqual(expect.arrayContaining(fields))
		expect(tool.parameters.additionalProperties).toBe(false)
	})
	it.each(names)('requires exact individual approval and verifies one native dispatch: %s', async name => {
		const run = await flow(name)
		try {
			const part = await pending(run.agent)
			expect(run.native.commit).not.toHaveBeenCalled()
			expect(part.request).toMatchObject({ canAllowAlways: false, canAllowForSession: false, arguments: { digest: 'b'.repeat(64), limitation: 'Native limitations; no Undo.' } })
			await run.agent.approve(part); await run.send
			expect(run.native.approve).toHaveBeenCalledTimes(1)
			expect(run.native.commit).toHaveBeenCalledTimes(1)
			expect(run.workspace.state).toMatchObject({ locked: false, phase: 'idle', latest: { outcome: 'committed', view: 'refreshed' } })
			expect(run.agent.chat.turns.flatMap(turn => [...turn.parts]).filter(p => p instanceof ToolCallPart && p.name === name)[0]).toMatchObject({ state: 'succeeded' })
		} finally { run.agent.stop(); await run.agent.dispose() }
	})
	it.each(['deny', 'stop', 'changed-preview'])('has no native effect after %s', async decision => {
		const run = await flow(names[0]!)
		try {
			const part = await pending(run.agent)
			if (decision === 'deny') await run.agent.deny(part)
			else if (decision === 'stop') run.agent.stop()
			else { part.request.arguments.target = '/Different'; await expect(run.agent.approve(part)).rejects.toThrow('proposal changed'); await run.agent.deny(part) }
			await run.send
			expect(run.native.commit).not.toHaveBeenCalled()
			expect(run.native.cancel).toHaveBeenCalled()
		} finally { run.agent.stop(); await run.agent.dispose() }
	})
	it('releases the shared overlay when commitment is verified but disclosure is restricted', async () => {
		const run = await flow(names[0]!, { restricted: true })
		try { await run.agent.approve(await pending(run.agent)); await run.send; expect(run.workspace.state).toMatchObject({ locked: false, phase: 'idle', latest: { outcome: 'committed', view: 'refresh_required' } }) }
		finally { run.agent.stop(); await run.agent.dispose() }
	})
	it.each([{ enabled: false }, { capabilities: [] }])('offers no mutation without the configured capability: %j', async options => {
		const run = await flow(names[0]!, options)
		try { await run.send; expect(run.native.propose).not.toHaveBeenCalled(); expect(run.native.commit).not.toHaveBeenCalled() }
		finally { run.agent.stop(); await run.agent.dispose() }
	})
})

describe('Native Files proposal transport', () => {
	it('cancels an aborted proposal by request nonce even before the server returns an ID', async () => {
		let finish!: (value: unknown) => void
		const post = vi.fn(() => new Promise(resolve => { finish = resolve }))
		const cleanup = vi.fn(async () => {})
		const api = fileOperationsApi(post as never, cleanup), stop = new AbortController()
		const work = api.propose({ operation: 'copy', sourceResourceId: 'nextcloud:files:51', destinationResourceId: 'nextcloud:files:50', destinationName: 'copy.md', expectedEtag: source.etag }, stop.signal)
		const rejection = expect(work).rejects.toThrow()
		stop.abort(); finish(proposal('copy')); await rejection
		expect(cleanup).toHaveBeenCalledWith(expect.stringMatching(/^request-/))
	})
	it.each([{ destinationResourceId: 'nextcloud:files:999' }, { expectedEtag: 'different' }, { toolName: 'nc_files_move' }])('refuses a changed native preview: %j', async change => {
		const cleanup = vi.fn(async () => {})
		const api = fileOperationsApi((async () => ({ ...proposal('copy'), ...change })) as never, cleanup)
		await expect(api.propose({ operation: 'copy', sourceResourceId: 'nextcloud:files:51', destinationResourceId: 'nextcloud:files:50', destinationName: 'copy.md', expectedEtag: source.etag }, new AbortController().signal)).rejects.toThrow('Invalid native Files preview')
		expect(cleanup).toHaveBeenCalledTimes(1)
	})
})
