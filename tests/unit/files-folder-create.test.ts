import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { FakeGateway } from '@fadenstack/testing'
import { ApprovalPart, ToolCallPart } from '@fadenstack/ui'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { NextcloudFiles } from '../../src/integrations/nextcloud/files.ts'
import { validateFolderName, filesFolderCreateTool } from '../../src/integrations/nextcloud/filesExtended.ts'
import { fileCapability } from '../../src/platform/catalog.ts'
import type { ScopePolicy } from '../../src/platform/scope.ts'
import type { FileApi, FileContext, Identity, FolderCreateProposal, FolderCreateOutcome, DavFolderCreatePlan } from '../../src/integrations/nextcloud/types.ts'
import { WorkspaceCoordinator } from '../../src/workspace/coordinator.ts'
import { folderOutcome, folderDavPlan } from '../../src/integrations/nextcloud/folderCreate.ts'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn(), request: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path: string) => `https://cloud.test${path}`,
	generateRemoteUrl: (name: string) => `https://cloud.test/remote.php/${name}/`,
}))
vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token-test' }))
vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, text: string) => text }))

const testPolicy: ScopePolicy = {
	version: 'folder-create-v1',
	gatewayOrigin: 'https://faden.test',
	sources: [{ kind: 'user-files' }],
	destinations: [{ kind: 'user-files' }],
	tools: ['nc_files_create_folder'],
	sourceClasses: ['standard'],
	downstreamPolicy: 'unverified',
	destinationAudiences: ['private'],
	movements: [{ source: 'standard', destination: 'private' }],
	developmentLoopback: true,
}

const hostIdentity: Identity = {
	userId: 'alice',
	binding: 'bind-folder-1',
	serverUrl: 'https://faden.test',
	enabled: true,
	agent: 'nc-folder-agent',
	development: true,
	folderCreateEnabled: true,
	scopePolicy: testPolicy,
}

const folderContext: FileContext = {
	id: 'ctx-folder-1',
	parentId: 1,
	expires: 9999999999,
	file: {
		id: 50,
		name: 'Team-Docs',
		path: '/Team-Docs',
		etag: 'parent-etag-1',
		mime: 'httpd/unix-directory',
		size: 0,
		folder: true,
		permissions: 31,
		url: 'https://cloud.test/f/50',
	},
}

const fileContext: FileContext = {
	id: 'ctx-file-1',
	parentId: 50,
	expires: 9999999999,
	file: {
		id: 51,
		name: 'Notes.txt',
		path: '/Team-Docs/Notes.txt',
		etag: 'notes-etag-1',
		mime: 'text/plain',
		size: 100,
		folder: false,
		permissions: 31,
		url: 'https://cloud.test/f/51',
	},
}

function createMockFilesApi(overrides: Partial<FileApi> = {}): FileApi {
	let createdName = 'NewFolder'
	return {
		identity: vi.fn(async () => hostIdentity),
		assertIdentity: vi.fn(async () => {}),
		select: vi.fn(async (fileId: number) => fileId === 51 ? fileContext : folderContext),
		read: vi.fn(async () => ({ file: fileContext.file, text: '', partial: false, totalCharacters: 0, processedCharacters: 0 })),
		pdf: vi.fn(async () => new Uint8Array()),
		list: vi.fn(async () => ({ folder: folderContext.file, files: [], partial: false })),
		propose: vi.fn(async () => ({ id: 'p1', target: '/a', before: null, contextId: 'c1', operation: 'create' as const, content: '' })),
		approve: vi.fn(async () => {}),
		commit: vi.fn(async () => ({ operation: 'create', file: folderContext.file })),
		scopeResource: vi.fn(async (resourceId: string) => ({
			resourceId,
			revision: 'parent-etag-1',
			sourceClass: 'standard',
			audience: 'private',
			scope: { kind: 'user-files' },
		})),
		proposeFolderCreate: vi.fn(async (resourceId: string, name: string, expectedRevision?: string): Promise<FolderCreateProposal> => {
			createdName = name
			return ({
			id: 'folder-prop-123',
			toolName: 'nc_files_create_folder',
			resourceId,
			folderId: 50,
			name,
			parentEtag: expectedRevision ?? 'parent-etag-1',
			expectedRevision: expectedRevision ?? 'parent-etag-1',
			target: `/Team-Docs/${name}`,
			expires: Math.floor(Date.now() / 1000) + 600,
			approved: false,
		}) }),
		approveFolderCreate: vi.fn(async () => {}),
		commitFolderCreate: vi.fn(async (): Promise<FolderCreateOutcome> => ({
			outcome: 'verified',
			proposalId: 'folder-prop-123',
			history: 'not-integrated',
			resourceId: 'nextcloud:files:999',
			name: createdName,
			parentResourceId: 'nextcloud:files:50',
			parentPath: '/Team-Docs',
			folder: {
				id: 999,
				name: createdName,
				path: `/Team-Docs/${createdName}`,
				etag: 'new-folder-etag-999',
				mime: 'httpd/unix-directory',
				size: 0,
				folder: true,
				permissions: 31,
				url: 'https://cloud.test/f/999',
			},
			observedState: {
				id: 999,
				name: createdName,
				path: `/Team-Docs/${createdName}`,
				etag: 'new-folder-etag-999',
				permissions: 31,
				is_directory: true,
			},
		})),
		...overrides,
	}
}

describe('NC08: Native Create Folder Prototype', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('registers tool capability with destructive risk, individual approval and not-integrated reversal', () => {
		const cap = fileCapability('nc_files_create_folder')
		expect(cap).toMatchObject({
			subsystem: 'files',
			risk: 'destructive',
			individualApproval: true,
			reversal: 'not-integrated',
		})
	})

	it('gates nc_files_create_folder when folderCreateEnabled is false or omitted', async () => {
		const files = createMockFilesApi()
		const gateway = new FakeGateway({
			agents: [{ slug: 'nc-folder-agent', version: 1, contract: [{ name: 'nc_files_create_folder', risk: 'destructive' }] }],
			users: [{ email: 'alice', password: 'test', agents: ['nc-folder-agent'] }],
			model: () => ({ text: 'No tool called' }),
		})

		const agentDisabled = new NextcloudAgent({ ...hostIdentity, folderCreateEnabled: false }, files, gateway.fetch)
		expect(agentDisabled.agent.tools.some(t => t.name === 'nc_files_create_folder')).toBe(false)
		await agentDisabled.dispose()

		const agentDefault = new NextcloudAgent({ ...hostIdentity, folderCreateEnabled: undefined }, files, gateway.fetch)
		expect(agentDefault.agent.tools.some(t => t.name === 'nc_files_create_folder')).toBe(false)
		await agentDefault.dispose()

		const agentNonDev = new NextcloudAgent({ ...hostIdentity, development: false, folderCreateEnabled: true }, files, gateway.fetch)
		expect(agentNonDev.agent.tools.some(t => t.name === 'nc_files_create_folder')).toBe(false)
		await agentNonDev.dispose()
	})

	it('validates folder names strictly according to rules', () => {
		expect(validateFolderName('Projects')).toBe('Projects')
		expect(validateFolderName('Projekte 📁')).toBe('Projekte 📁')
		expect(validateFolderName('日本語フォルダ')).toBe('日本語フォルダ')
		expect(validateFolderName('Entwürfe & Pläne')).toBe('Entwürfe & Pläne')

		expect(() => validateFolderName('')).toThrow('A folder name of 1–100 characters')
		expect(() => validateFolderName('   ')).toThrow('A folder name of 1–100 characters')
		expect(() => validateFolderName('.')).toThrow('A folder name of 1–100 characters')
		expect(() => validateFolderName('..')).toThrow('A folder name of 1–100 characters')
		expect(() => validateFolderName('path/traversal')).toThrow('A folder name of 1–100 characters')
		expect(() => validateFolderName('win\\path')).toThrow('A folder name of 1–100 characters')
		expect(() => validateFolderName('colon:name')).toThrow('A folder name of 1–100 characters')
		expect(() => validateFolderName('wild*card')).toThrow('A folder name of 1–100 characters')
		expect(() => validateFolderName('a'.repeat(101))).toThrow('A folder name of 1–100 characters')
	})

	it('rejects folder creation when active context is a file (no parent-root fallback)', async () => {
		const files = createMockFilesApi()
		const gateway = new FakeGateway({
			agents: [{ slug: 'nc-folder-agent', version: 1, contract: [{ name: 'nc_files_create_folder', risk: 'destructive' }] }],
			users: [{ email: 'alice', password: 'test', agents: ['nc-folder-agent'] }],
			model: () => ({
				toolCalls: [{
					id: 'call-folder-file-ctx',
					name: 'nc_files_create_folder',
					arguments: { name: 'SubFolder' },
				}],
			}),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'test')
		agent.offerSelection(await agent.selection.select(folderContext.file.id))
		agent.offerSelection(await agent.selection.select(fileContext.file.id))

		await agent.send('Create a folder inside the selected file', true)
		expect(agent.chat.turns[0].state).toBe('failed')
		expect(agent.chat.turns[0].errorMessage).toContain('Folder creation requires an explicitly chosen folder, not a file.')
		expect(files.proposeFolderCreate).not.toHaveBeenCalled()
		await agent.dispose()
	})

	it('executes full exact approved flow with native proposals, verification and history=not-integrated', async () => {
		const files = createMockFilesApi()
		let round = 0
		const gateway = new FakeGateway({
			agents: [{ slug: 'nc-folder-agent', version: 1, contract: [{ name: 'nc_files_create_folder', risk: 'destructive' }] }],
			users: [{ email: 'alice', password: 'test', agents: ['nc-folder-agent'] }],
			model: () => ++round === 1
				? ({
					toolCalls: [{
						id: 'call-folder-create-1',
						name: 'nc_files_create_folder',
						arguments: { name: 'NewFolder', resource_id: 'nextcloud:files:50', expectedRevision: 'parent-etag-1' },
					}],
				})
				: ({ text: 'Folder created successfully.' }),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'test')
		agent.offerSelection(await agent.selection.select(folderContext.file.id))

		const sendPromise = agent.send('Create folder NewFolder in nextcloud:files:50', true)

		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		expect(part!.request.toolName).toBe('nc_files_create_folder')
		expect(part!.request.canAllowAlways).toBe(false)
		expect(part!.request.canAllowForSession).toBe(false)
		expect(part!.request.arguments.proposalId).toBe('folder-prop-123')
		expect(part!.request.arguments.target).toBe('/Team-Docs/NewFolder')
		expect(part!.request.arguments.parentEtag).toBe('parent-etag-1')

		expect(files.proposeFolderCreate).toHaveBeenCalledWith('nextcloud:files:50', 'NewFolder', 'parent-etag-1', expect.any(Object))

		await agent.approve(part!)
		expect(files.approveFolderCreate).toHaveBeenCalledWith('folder-prop-123', expect.any(Object))

		await sendPromise
		expect(files.commitFolderCreate).toHaveBeenCalledWith('folder-prop-123', expect.any(Object))

		// Check tool call succeeded
		const toolPart = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ToolCallPart)
		expect(toolPart).toBeDefined()
		expect((toolPart as ToolCallPart).state).toBe('succeeded')

		// Verify result payload was recorded and history is not-integrated
		;(toolPart as ToolCallPart).toggle()
		expect((toolPart as ToolCallPart).resultText).toContain('"outcome":"verified"')
		expect((toolPart as ToolCallPart).resultText).toContain('"history":"not-integrated"')
		expect((toolPart as ToolCallPart).resultText).toContain('nextcloud:files:999')

		await agent.dispose()
	})

	it('refuses execution if tool arguments are substituted after approval', async () => {
		const files = createMockFilesApi()
		let round = 0
		const gateway = new FakeGateway({
			agents: [{ slug: 'nc-folder-agent', version: 1, contract: [{ name: 'nc_files_create_folder', risk: 'destructive' }] }],
			users: [{ email: 'alice', password: 'test', agents: ['nc-folder-agent'] }],
			model: () => ++round === 1
				? ({
					toolCalls: [{
						id: 'call-folder-tamper',
						name: 'nc_files_create_folder',
						arguments: { name: 'ValidFolder', resource_id: 'nextcloud:files:50' },
					}],
				})
				: ({ text: 'Done' }),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'test')
		agent.offerSelection(await agent.selection.select(folderContext.file.id))

		const tool = agent.agent.find('nc_files_create_folder')!
		const invoke = tool.invoke.bind(tool)
		tool.invoke = (finalArgs, call) => invoke({ ...finalArgs, name: 'SubstitutedFolder' }, call)

		const sendPromise = agent.send('Create folder ValidFolder', true)

		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		await agent.approve(part!)
		await sendPromise

		expect(files.commitFolderCreate).not.toHaveBeenCalled()
		expect(JSON.stringify(gateway.modelCalls.flatMap(call => call.messages))).toContain('arguments changed')
		await agent.dispose()
	})

	it('stops before dispatch without creating anything when Stop is triggered', async () => {
		const files = createMockFilesApi()
		const gateway = new FakeGateway({
			agents: [{ slug: 'nc-folder-agent', version: 1, contract: [{ name: 'nc_files_create_folder', risk: 'destructive' }] }],
			users: [{ email: 'alice', password: 'test', agents: ['nc-folder-agent'] }],
			model: () => ({
				toolCalls: [{
					id: 'call-folder-stop-1',
					name: 'nc_files_create_folder',
					arguments: { name: 'PendingFolder', resource_id: 'nextcloud:files:50' },
				}],
			}),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'test')
		agent.offerSelection(await agent.selection.select(folderContext.file.id))

		const sendPromise = agent.send('Create folder PendingFolder', true)

		await vi.waitFor(() => {
			const part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart)
			expect(part?.isPending).toBe(true)
		})

		// Trigger Stop before approving
		agent.stop()

		await sendPromise
		expect(agent.chat.turns[0].state).toBe('stopped')
		expect(files.approveFolderCreate).not.toHaveBeenCalled()
		expect(files.commitFolderCreate).not.toHaveBeenCalled()
		await agent.dispose()
	})

	it('rejects replaying a consumed approval', async () => {
		const files = createMockFilesApi({
			commitFolderCreate: vi.fn()
				.mockResolvedValueOnce({
					outcome: 'verified',
			proposalId: 'folder-prop-123',
					history: 'not-integrated',
					resourceId: 'nextcloud:files:1001',
					name: 'Folder1',
					parentResourceId: 'nextcloud:files:50',
					parentPath: '/Team-Docs',
					folder: { ...folderContext.file, id: 1001, name: 'Folder1', path: '/Team-Docs/Folder1', etag: 'e1' },
					observedState: { id: 1001, name: 'Folder1', path: '/Team-Docs/Folder1', etag: 'e1', permissions: 31, is_directory: true },
				})
				.mockRejectedValueOnce(new Error('The folder proposal expired or was already consumed.')),
		})

		let round = 0
		const gateway = new FakeGateway({
			agents: [{ slug: 'nc-folder-agent', version: 1, contract: [{ name: 'nc_files_create_folder', risk: 'destructive' }] }],
			users: [{ email: 'alice', password: 'test', agents: ['nc-folder-agent'] }],
			model: () => ++round === 1
				? ({
					toolCalls: [{
						id: 'call-first-run',
						name: 'nc_files_create_folder',
						arguments: { name: 'Folder1', resource_id: 'nextcloud:files:50' },
					}],
				})
				: ({ text: 'Done' }),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'test')
		agent.offerSelection(await agent.selection.select(folderContext.file.id))

		const sendPromise = agent.send('Create Folder1', true)
		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		await agent.approve(part!)
		await sendPromise

		expect(files.commitFolderCreate).toHaveBeenCalledTimes(1)

		// Attempting to commit again directly on the same proposal ID fails
		await expect(agent.approve(part!)).rejects.toThrow('no longer pending')
		expect(files.commitFolderCreate).toHaveBeenCalledTimes(1)
		await agent.dispose()
	})
})

async function folderFlow(args: Record<string, unknown>, overrides: Partial<FileApi> = {}, selected = true) {
	const files = createMockFilesApi(overrides)
	let round = 0
	const gateway = new FakeGateway({
		agents: [{ slug: 'nc-folder-agent', version: 1, contract: [{ name: 'nc_files_create_folder', risk: 'destructive' }] }],
		users: [{ email: 'alice', password: 'test', agents: ['nc-folder-agent'] }],
		model: () => ++round === 1 ? ({ toolCalls: [{ id: 'review-folder', name: 'nc_files_create_folder', arguments: args }] }) : ({ text: 'Receipt inspected.' }),
	})
	const refresh = vi.fn(async () => 'refreshed' as const)
	const view = { app: 'files', key: '/apps/files', epoch: 1, editState: 'clean' as const, reason: 'Save edits first' }
	const workspace = new WorkspaceCoordinator({ guard: { snapshot: () => view, dispose: () => {} }, refresh })
	const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch, workspace)
	await agent.signIn('alice', 'test')
	if (selected) agent.offerSelection(await agent.selection.select(folderContext.file.id))
	const send = agent.send('Create the requested folder', selected)
	return { agent, files, gateway, workspace, refresh, send }
}

async function pendingFolder(agent: NextcloudAgent): Promise<ApprovalPart> {
	let part: ApprovalPart | undefined
	await vi.waitFor(() => {
		part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart && p.isPending) as ApprovalPart | undefined
		expect(part).toBeDefined()
	})
	return part!
}

describe('Independent folder creation regressions', () => {
	beforeEach(() => { vi.clearAllMocks() })
	it('does not introduce a model-invented destination even with user-files destination policy', async () => {
		const flow = await folderFlow({ name: 'NewFolder', resource_id: 'nextcloud:files:4321' }, {}, false)
		try {
			await vi.waitFor(() => expect(flow.agent.chat.turns[0].state === 'failed' || flow.agent.chat.turns[0].parts.some(p => p instanceof ApprovalPart)).toBe(true))
			expect(flow.files.proposeFolderCreate).not.toHaveBeenCalled()
			expect(flow.agent.chat.turns[0].errorMessage).toContain('known destination')
		} finally { flow.agent.stop(); await flow.send; await flow.agent.dispose() }
	})

	it('binds an omitted revision to the freshly authorized parent revision', async () => {
		const flow = await folderFlow({ name: 'NewFolder' })
		try {
			const part = await pendingFolder(flow.agent)
			expect(flow.files.proposeFolderCreate).toHaveBeenCalledWith('nextcloud:files:50', 'NewFolder', 'parent-etag-1', expect.any(AbortSignal))
			part.deny()
		} finally { flow.agent.stop(); await flow.send; await flow.agent.dispose() }
	})

	it('refuses removal of an approved optional revision at the SDK invocation boundary', async () => {
		const flow = await folderFlow({ name: 'NewFolder', resource_id: 'nextcloud:files:50', expectedRevision: 'parent-etag-1' })
		try {
			const tool = flow.agent.agent.find('nc_files_create_folder')!
			const invoke = tool.invoke.bind(tool)
			tool.invoke = (args, call) => { const { expectedRevision: _removed, ...changed } = args; return invoke(changed, call) }
			await flow.agent.approve(await pendingFolder(flow.agent))
			await flow.send
			expect(flow.files.commitFolderCreate).not.toHaveBeenCalled()
			expect(flow.workspace.state.latest).toBeUndefined()
		} finally { flow.agent.stop(); await flow.send; await flow.agent.dispose() }
	})

	it('refuses a substituted approval preview before native approval', async () => {
		const flow = await folderFlow({ name: 'NewFolder' })
		try {
			const part = await pendingFolder(flow.agent)
			;(part.request.arguments as Record<string, unknown>).target = '/Different/NewFolder'
			await expect(flow.agent.approve(part)).rejects.toThrow('preview changed')
			expect(flow.files.approveFolderCreate).not.toHaveBeenCalled()
			expect(flow.files.commitFolderCreate).not.toHaveBeenCalled()
		} finally { flow.agent.stop(); await flow.send; await flow.agent.dispose() }
	})

	it('rechecks the destination audience after approval and before dispatch', async () => {
		const flow = await folderFlow({ name: 'NewFolder' })
		try {
			const part = await pendingFolder(flow.agent)
			vi.mocked(flow.files.scopeResource!).mockImplementation(async reference => ({ resourceId: reference, revision: 'parent-etag-1', sourceClass: 'standard', audience: 'shared', scope: { kind: 'user-files' } }))
			await flow.agent.approve(part)
			await flow.send
			expect(flow.files.commitFolderCreate).not.toHaveBeenCalled()
		} finally { flow.agent.stop(); await flow.send; await flow.agent.dispose() }
	})

	it.each(['not-dispatched', 'ambiguous'] as const)('keeps a %s receipt separate from a confirmed save', async outcome => {
		const flow = await folderFlow({ name: 'NewFolder' }, { commitFolderCreate: vi.fn(async () => ({ outcome, history: 'not-integrated', proposalId: 'folder-prop-123', name: 'NewFolder', parentResourceId: 'nextcloud:files:50', target: '/Team-Docs/NewFolder', message: 'Inspect the destination.' })) })
		try {
			await flow.agent.approve(await pendingFolder(flow.agent))
			await flow.send
			expect(flow.workspace.state.latest?.outcome).toBe(outcome === 'not-dispatched' ? 'not_dispatched' : 'unknown_completion')
			expect(flow.refresh).not.toHaveBeenCalled()
			expect(flow.files.commitFolderCreate).toHaveBeenCalledTimes(1)
		} finally { await flow.agent.dispose() }
	})

	it('preserves verified completion when Stop occurs during a noncooperating dispatch', async () => {
		const ordinary = createMockFilesApi()
		let complete!: (value: FolderCreateOutcome) => void
		const flow = await folderFlow({ name: 'NewFolder' }, { commitFolderCreate: vi.fn(() => new Promise(resolve => { complete = resolve })) })
		try {
			await flow.agent.approve(await pendingFolder(flow.agent))
			await vi.waitFor(() => expect(flow.files.commitFolderCreate).toHaveBeenCalledTimes(1))
			flow.agent.stop()
			expect(flow.agent.chat.isBusy).toBe(true)
			complete(await ordinary.commitFolderCreate!('folder-prop-123'))
			await flow.send
			expect(flow.workspace.state.latest).toMatchObject({ outcome: 'committed', operation: 'create_folder', view: 'refresh_required' })
			expect(flow.refresh).not.toHaveBeenCalled()
			expect(flow.gateway.modelCalls).toHaveLength(1)
		} finally { flow.agent.stop(); await flow.agent.dispose() }
	})

	it('keeps lost-response completion unknown and does not retry', async () => {
		const flow = await folderFlow({ name: 'NewFolder' }, { commitFolderCreate: vi.fn(async () => { throw new Error('Lost response after dispatch') }) })
		try {
			await flow.agent.approve(await pendingFolder(flow.agent))
			await flow.send
			expect(flow.workspace.state.latest?.outcome).toBe('unknown_completion')
			expect(flow.files.commitFolderCreate).toHaveBeenCalledTimes(1)
			expect(flow.refresh).not.toHaveBeenCalled()
		} finally { await flow.agent.dispose() }
	})

	it('uses the same Unicode character and UTF-8 byte limits as native validation', () => {
		expect(validateFolderName('ä'.repeat(100))).toBe('ä'.repeat(100))
		expect(() => validateFolderName('📁'.repeat(64))).toThrow()
		expect(() => validateFolderName(' padded ')).toThrow()
		expect(() => validateFolderName('\ud800')).toThrow()
	})

	it('requires the native policy and the complete adapter lifecycle before offering the tool', async () => {
		const noPolicy = new NextcloudAgent({ ...hostIdentity, scopePolicy: undefined }, createMockFilesApi())
		const noCommit = new NextcloudAgent(hostIdentity, createMockFilesApi({ commitFolderCreate: undefined }))
		try {
			expect(noPolicy.agent.tools.some(tool => tool.name === 'nc_files_create_folder')).toBe(false)
			expect(noCommit.agent.tools.some(tool => tool.name === 'nc_files_create_folder')).toBe(false)
		} finally { await noPolicy.dispose(); await noCommit.dispose() }
	})

	it('does not dispatch after Stop during a delayed native approval response', async () => {
		let approveDone!: () => void
		const flow = await folderFlow({ name: 'NewFolder' }, { approveFolderCreate: vi.fn(() => new Promise<void>(resolve => { approveDone = resolve })) })
		try {
			const approving = flow.agent.approve(await pendingFolder(flow.agent))
			await vi.waitFor(() => expect(flow.files.approveFolderCreate).toHaveBeenCalledTimes(1))
			flow.agent.stop()
			await flow.send
			approveDone()
			await expect(approving).rejects.toThrow('no longer pending')
			expect(flow.files.commitFolderCreate).not.toHaveBeenCalled()
		} finally { await flow.agent.dispose() }
	})

	it('preserves the original protected lineage after a source is reclassified standard', async () => {
		const policy: ScopePolicy = { ...testPolicy, sourceClasses: ['standard', 'protected'], downstreamPolicy: 'configured' }
		let classification: 'protected' | 'standard' = 'protected'
		const files = createMockFilesApi({ scopeResource: vi.fn(async reference => ({ resourceId: reference, revision: 'parent-etag-1', sourceClass: classification, audience: 'private', scope: { kind: 'user-files' } })) })
		let round = 0
		const gateway = new FakeGateway({
			agents: [{ slug: 'nc-folder-agent', version: 1, contract: [{ name: 'nc_files_create_folder', risk: 'destructive' }] }],
			users: [{ email: 'alice', password: 'test', agents: ['nc-folder-agent'] }],
			model: () => ++round === 1 ? ({ text: 'Included folder inspected.' }) : ({ toolCalls: [{ id: 'lineage-folder', name: 'nc_files_create_folder', arguments: { name: 'NewFolder' } }] }),
		})
		const agent = new NextcloudAgent({ ...hostIdentity, scopePolicy: policy }, files, gateway.fetch)
		try {
			await agent.signIn('alice', 'test')
			agent.offerSelection(await agent.selection.select(50))
			await agent.send('Use the included folder', true)
			classification = 'standard'
			await agent.send('Create a folder using the prior context', true)
			expect(files.proposeFolderCreate).not.toHaveBeenCalled()
			expect(agent.chat.turns[1].errorMessage).toContain('Earlier conversation content')
		} finally { agent.stop(); await agent.dispose() }
	})

	it('does not dispatch when a native draft appears during approval', async () => {
		const flow = await folderFlow({ name: 'NewFolder' })
		try {
			const part = await pendingFolder(flow.agent)
			vi.spyOn(flow.workspace.dependencies.guard, 'snapshot').mockReturnValue({ app: 'files', key: '/apps/files', epoch: 1, editState: 'dirty', reason: 'Save edits first' })
			await flow.agent.approve(part)
			await flow.send
			expect(flow.files.commitFolderCreate).not.toHaveBeenCalled()
			expect(flow.refresh).not.toHaveBeenCalled()
		} finally { flow.agent.stop(); await flow.send; await flow.agent.dispose() }
	})

	it('projects verified receipts and refuses a mismatched native identity', async () => {
		const files = createMockFilesApi()
		const proposal = await files.proposeFolderCreate!('nextcloud:files:50', 'NewFolder', 'parent-etag-1')
		const outcome = await files.commitFolderCreate!('folder-prop-123')
		if (outcome.outcome !== 'verified') throw new Error('Expected synthetic verified fixture')
		const response = { ...outcome, token: 'synthetic-secret', folder: { ...outcome.folder, token: 'synthetic-secret', url: 'https://unapproved.test/logout' } }
		const projected = folderOutcome(response, proposal)
		expect(JSON.stringify(projected)).not.toContain('synthetic-secret')
		expect(JSON.stringify(projected)).not.toContain('unapproved.test')
		expect(() => folderOutcome({ ...outcome, resourceId: 'nextcloud:files:50' }, proposal)).toThrow('Invalid native folder receipt')
	})

	it('dispatches conditional MKCOL with If-None-Match: * and finishes creation on 201', async () => {
		const files = new NextcloudFiles(hostIdentity)
		vi.mocked(axios.get).mockResolvedValue({ data: hostIdentity } as never)
		const plan: DavFolderCreatePlan = {
			kind: 'dav-create-folder',
			receiptId: 'folder-prop-1',
			dav_path: '/files/alice/Team-Docs/NewFolder',
			parent_resource_id: 'nextcloud:files:50',
			parent_path: '/Team-Docs',
			parent_etag: 'parent-etag-1',
			name: 'NewFolder',
			target: '/Team-Docs/NewFolder',
		}
		const finishOutcome: FolderCreateOutcome = {
			outcome: 'verified',
			history: 'not-integrated',
			proposalId: 'folder-prop-1',
			resourceId: 'nextcloud:files:999',
			name: 'NewFolder',
			parentResourceId: 'nextcloud:files:50',
			parentPath: '/Team-Docs',
			target: '/Team-Docs/NewFolder',
			folder: {
				id: 999,
				name: 'NewFolder',
				path: '/Team-Docs/NewFolder',
				etag: 'folder-etag-999',
				mime: 'httpd/unix-directory',
				size: 0,
				folder: true,
				permissions: 31,
				url: 'https://cloud.test/f/999',
			},
			observedState: {
				id: 999,
				name: 'NewFolder',
				path: '/Team-Docs/NewFolder',
				etag: 'folder-etag-999',
				permissions: 31,
				is_directory: true,
			},
		}

		vi.mocked(axios.post).mockImplementation(async (url: string) => {
			if (url.includes('/files/folder/folder-prop-1/commit')) {
				return { data: plan } as never
			}
			if (url.includes('/search/resolve')) {
				return {
					data: [{
						id: 50,
						name: 'Team-Docs',
						path: '/Team-Docs',
						etag: 'parent-etag-1',
						mime: 'httpd/unix-directory',
						size: 0,
						folder: true,
						permissions: 31,
						url: 'https://cloud.test/f/50',
					}],
				} as never
			}
			if (url.includes('/files/folder/finish')) {
				return { data: finishOutcome } as never
			}
			throw new Error(`Unexpected POST url: ${url}`)
		})

		vi.mocked(axios.request).mockImplementation(async (config: any) => {
			if (config.method === 'MKCOL') {
				expect(config.headers['If-None-Match']).toBe('*')
				expect(config.url).toContain('/remote.php/dav/files/alice/Team-Docs/NewFolder')
				return { status: 201, headers: { 'oc-fileid': '999' } } as never
			}
			throw new Error(`Unexpected request config: ${JSON.stringify(config)}`)
		})

		const outcome = await files.commitFolderCreate('folder-prop-1')
		expect(outcome.outcome).toBe('verified')
		expect(outcome.history).toBe('not-integrated')
		expect(outcome.resourceId).toBe('nextcloud:files:999')
	})

	it('does not infer absence of effects from an error after MKCOL dispatch', async () => {
		const files = new NextcloudFiles(hostIdentity)
		vi.mocked(axios.get).mockResolvedValue({ data: hostIdentity } as never)
		const plan: DavFolderCreatePlan = {
			kind: 'dav-create-folder',
			receiptId: 'folder-prop-1',
			dav_path: '/files/alice/Team-Docs/ExistingFolder',
			parent_resource_id: 'nextcloud:files:50',
			parent_path: '/Team-Docs',
			parent_etag: 'parent-etag-1',
			name: 'ExistingFolder',
			target: '/Team-Docs/ExistingFolder',
		}
		vi.mocked(axios.post).mockImplementation(async (url: string) => {
			if (url.includes('/commit')) return { data: plan } as never
			if (url.includes('/search/resolve')) {
				return {
					data: [{
						id: 50,
						name: 'Team-Docs',
						path: '/Team-Docs',
						etag: 'parent-etag-1',
						folder: true,
						permissions: 31,
					}],
				} as never
			}
			throw new Error(`Unexpected POST url: ${url}`)
		})
		vi.mocked(axios.request).mockResolvedValue({ status: 412, headers: {} } as never)

		const outcome = await files.commitFolderCreate('folder-prop-1')
		expect(outcome.outcome).toBe('ambiguous')
		expect(outcome.message).toContain('status 412')
	})

	it('keeps a dispatched 423 outcome uncertain without a verified receipt', async () => {
		const files = new NextcloudFiles(hostIdentity)
		vi.mocked(axios.get).mockResolvedValue({ data: hostIdentity } as never)
		const plan: DavFolderCreatePlan = {
			kind: 'dav-create-folder',
			receiptId: 'folder-prop-1',
			dav_path: '/files/alice/Team-Docs/BusyFolder',
			parent_resource_id: 'nextcloud:files:50',
			parent_path: '/Team-Docs',
			parent_etag: 'parent-etag-1',
			name: 'BusyFolder',
			target: '/Team-Docs/BusyFolder',
		}
		vi.mocked(axios.post).mockImplementation(async (url: string) => {
			if (url.includes('/commit')) return { data: plan } as never
			if (url.includes('/search/resolve')) {
				return {
					data: [{
						id: 50,
						name: 'Team-Docs',
						path: '/Team-Docs',
						etag: 'parent-etag-1',
						folder: true,
						permissions: 31,
					}],
				} as never
			}
			throw new Error(`Unexpected POST url: ${url}`)
		})
		vi.mocked(axios.request).mockResolvedValue({ status: 423, headers: {} } as never)

		const outcome = await files.commitFolderCreate('folder-prop-1')
		expect(outcome.outcome).toBe('ambiguous')
		expect(outcome.message).toContain('status 423')
	})

	it('reports ambiguous and does not retry when MKCOL network request fails', async () => {
		const files = new NextcloudFiles(hostIdentity)
		vi.mocked(axios.get).mockResolvedValue({ data: hostIdentity } as never)
		const plan: DavFolderCreatePlan = {
			kind: 'dav-create-folder',
			receiptId: 'folder-prop-1',
			dav_path: '/files/alice/Team-Docs/NetworkErrFolder',
			parent_resource_id: 'nextcloud:files:50',
			parent_path: '/Team-Docs',
			parent_etag: 'parent-etag-1',
			name: 'NetworkErrFolder',
			target: '/Team-Docs/NetworkErrFolder',
		}
		vi.mocked(axios.post).mockImplementation(async (url: string) => {
			if (url.includes('/commit')) return { data: plan } as never
			if (url.includes('/search/resolve')) {
				return {
					data: [{
						id: 50,
						name: 'Team-Docs',
						path: '/Team-Docs',
						etag: 'parent-etag-1',
						folder: true,
						permissions: 31,
					}],
				} as never
			}
			throw new Error(`Unexpected POST url: ${url}`)
		})
		vi.mocked(axios.request).mockRejectedValue(new Error('Network disconnected') as never)

		const outcome = await files.commitFolderCreate('folder-prop-1')
		expect(outcome.outcome).toBe('ambiguous')
		expect(outcome.message).toContain('could not be confirmed')
		expect(axios.request).toHaveBeenCalledTimes(1)
	})

	it('refuses to write when parent folder destination changed before MKCOL', async () => {
		const files = new NextcloudFiles(hostIdentity)
		vi.mocked(axios.get).mockResolvedValue({ data: hostIdentity } as never)
		vi.mocked(axios.request).mockClear()
		const plan: DavFolderCreatePlan = {
			kind: 'dav-create-folder',
			receiptId: 'folder-prop-1',
			dav_path: '/files/alice/Team-Docs/NewFolder',
			parent_resource_id: 'nextcloud:files:50',
			parent_path: '/Team-Docs',
			parent_etag: 'parent-etag-1',
			name: 'NewFolder',
			target: '/Team-Docs/NewFolder',
		}
		vi.mocked(axios.post).mockImplementation(async (url: string) => {
			if (url.includes('/commit')) return { data: plan } as never
			if (url.includes('/search/resolve')) {
				return {
					data: [{
						id: 50,
						name: 'Team-Docs',
						path: '/Team-Docs',
						etag: 'changed-parent-etag-2',
						folder: true,
						permissions: 31,
					}],
				} as never
			}
			throw new Error(`Unexpected POST url: ${url}`)
		})

		await expect(files.commitFolderCreate('folder-prop-1')).rejects.toThrow('approved destination changed')
		expect(axios.request).not.toHaveBeenCalled()
	})

	it('cancels pending folder proposals via cancelFolderCreate to recover quota', async () => {
		const files = new NextcloudFiles(hostIdentity)
		vi.mocked(axios.get).mockResolvedValue({ data: hostIdentity } as never)
		vi.mocked(axios.post).mockResolvedValue({ data: { cancelled: true } } as never)

		await files.cancelFolderCreate('folder-prop-123')
		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/files/folder/folder-prop-123/cancel'),
			{},
			expect.objectContaining({ headers: expect.any(Object) }),
		)
	})

	it('calls cancelFolderCreate on server when user denies proposal', async () => {
		const cancelMock = vi.fn(async () => {})
		const flow = await folderFlow({ name: 'NewFolder' }, { cancelFolderCreate: cancelMock })
		try {
			const part = await pendingFolder(flow.agent)
			await flow.agent.deny(part)
			expect(cancelMock).toHaveBeenCalledWith('folder-prop-123')
		} finally { await flow.agent.dispose() }
	})

	it('calls cancelFolderCreate on server when user stops agent with pending proposals', async () => {
		const cancelMock = vi.fn(async () => {})
		const flow = await folderFlow({ name: 'NewFolder' }, { cancelFolderCreate: cancelMock })
		try {
			await pendingFolder(flow.agent)
			flow.agent.stop()
			expect(cancelMock).toHaveBeenCalledWith('folder-prop-123')
		} finally { await flow.agent.dispose() }
	})

	it.each([
		{ dav_path: '/files/alice/Team-Docs/../Different/NewFolder' },
		{ dav_path: '/files/alice/Different/NewFolder' },
		{ receiptId: 'different-proposal' },
		{ parent_resource_id: '50' },
		{ parent_path: '/Team-Docs//' },
		{ target: '/Team-Docs/Different' },
	])('refuses an inconsistent DAV plan before dispatch: %j', changed => {
		const plan: DavFolderCreatePlan = { kind: 'dav-create-folder', receiptId: 'p1', dav_path: '/files/alice/Team-Docs/NewFolder', parent_resource_id: 'nextcloud:files:50', parent_path: '/Team-Docs', parent_etag: 'e1', name: 'NewFolder', target: '/Team-Docs/NewFolder' }
		expect(() => folderDavPlan({ ...plan, ...changed }, 'p1', 'alice')).toThrow('Invalid approved')
	})

	it('matches native raw URL encoding for Unicode and punctuation', () => {
		const plan: DavFolderCreatePlan = { kind: 'dav-create-folder', receiptId: 'p1', dav_path: '/files/alice/Team-Docs/%C3%9Cber%21%20%27%28%29', parent_resource_id: 'nextcloud:files:50', parent_path: '/Team-Docs', parent_etag: 'e1', name: "Über! '()", target: "/Team-Docs/Über! '()" }
		expect(folderDavPlan(plan, 'p1', 'alice')).toEqual(plan)
	})

	it('ends local approval immediately when native cancellation is delayed', async () => {
		let finishCancel!: () => void
		const flow = await folderFlow({ name: 'NewFolder' }, { cancelFolderCreate: vi.fn(() => new Promise<void>(resolve => { finishCancel = resolve })) })
		try {
			const part = await pendingFolder(flow.agent)
			const cancelling = flow.agent.deny(part)
			expect(part.isPending).toBe(false)
			await flow.send
			expect(flow.agent.chat.isBusy).toBe(false)
			expect(flow.files.commitFolderCreate).not.toHaveBeenCalled()
			finishCancel()
			await cancelling
		} finally { flow.agent.stop(); await flow.agent.dispose() }
	})

	it('cancels a noncooperating proposal that arrives after Stop', async () => {
		let finishPreview!: (value: FolderCreateProposal) => void
		const ordinary = createMockFilesApi()
		const flow = await folderFlow({ name: 'NewFolder' }, { proposeFolderCreate: vi.fn(() => new Promise(resolve => { finishPreview = resolve })), cancelFolderCreate: vi.fn(async () => {}) })
		try {
			await vi.waitFor(() => expect(flow.files.proposeFolderCreate).toHaveBeenCalledTimes(1))
			flow.agent.stop()
			finishPreview(await ordinary.proposeFolderCreate!('nextcloud:files:50', 'NewFolder', 'parent-etag-1'))
			await flow.send
			expect(flow.files.cancelFolderCreate).toHaveBeenCalledWith('folder-prop-123')
			expect(flow.files.commitFolderCreate).not.toHaveBeenCalled()
		} finally { flow.agent.stop(); await flow.agent.dispose() }
	})

	it('sends bounded native-only cancellation after configuration changes', async () => {
		const files = new NextcloudFiles({ ...hostIdentity, historyEnabled: true })
		vi.mocked(axios.get).mockRejectedValue(new Error('Configuration changed'))
		vi.mocked(axios.post).mockResolvedValue({ data: { cancelled: true } } as never)
		await files.cancelFolderCreate('folder-prop-123')
		expect(axios.get).not.toHaveBeenCalled()
		expect(axios.post).toHaveBeenCalledWith(expect.stringContaining('/cancel'), {}, expect.objectContaining({ timeout: 5000, headers: { 'X-Fadenstack-Session': hostIdentity.binding } }))
	})

	it('cancels an aborted native preview by its known request nonce before any response', async () => {
		const files = new NextcloudFiles(hostIdentity)
		vi.mocked(axios.get).mockResolvedValue({ data: hostIdentity } as never)
		let finishPreview!: (response: unknown) => void
		let nonce = ''
		vi.mocked(axios.post).mockImplementation(async (url, body) => {
			if (String(url).endsWith('/propose')) {
				nonce = (body as Record<string, string>).clientRequestId
				return new Promise(resolve => { finishPreview = resolve }) as never
			}
			return { data: { cancelled: true } } as never
		})
		const stop = new AbortController()
		const pending = files.proposeFolderCreate('nextcloud:files:50', 'NewFolder', 'e1', stop.signal)
		const rejected = expect(pending).rejects.toThrow('Stopped')
		await vi.waitFor(() => expect(nonce).toMatch(/^request-/))
		stop.abort(new DOMException('Stopped', 'AbortError'))
		expect(axios.post).toHaveBeenCalledWith(expect.stringContaining(`/${nonce}/cancel`), {}, expect.any(Object))
		finishPreview({ data: {} })
		await rejected
	})

	it.each([undefined, '../wrong-id'])('does not finalize a 201 without a valid creation identity: %s', async fileId => {
		const files = new NextcloudFiles(hostIdentity)
		vi.mocked(axios.get).mockResolvedValue({ data: hostIdentity } as never)
		const plan: DavFolderCreatePlan = { kind: 'dav-create-folder', receiptId: 'p1', dav_path: '/files/alice/Team-Docs/NewFolder', parent_resource_id: 'nextcloud:files:50', parent_path: '/Team-Docs', parent_etag: 'e1', name: 'NewFolder', target: '/Team-Docs/NewFolder' }
		vi.mocked(axios.post).mockImplementation(async (url) => {
			if (String(url).endsWith('/commit')) return { data: plan } as never
			if (String(url).includes('/search/resolve')) return { data: [{ ...folderContext.file, etag: 'e1' }] } as never
			throw new Error('Unexpected finalization')
		})
		vi.mocked(axios.request).mockResolvedValue({ status: 201, headers: { 'oc-fileid': fileId } } as never)
		expect((await files.commitFolderCreate('p1')).outcome).toBe('ambiguous')
		expect(vi.mocked(axios.post).mock.calls.some(([url]) => String(url).endsWith('/finish'))).toBe(false)
		expect(axios.request).toHaveBeenCalledTimes(1)
	})
})
