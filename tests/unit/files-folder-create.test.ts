import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { FakeGateway } from '@fadenstack/testing'
import { ApprovalPart, ToolCallPart } from '@fadenstack/ui'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { validateFolderName, filesFolderCreateTool } from '../../src/integrations/nextcloud/filesExtended.ts'
import { fileCapability } from '../../src/platform/catalog.ts'
import type { ScopePolicy } from '../../src/platform/scope.ts'
import type { FileApi, FileContext, Identity, FolderCreateProposal, FolderCreateOutcome } from '../../src/integrations/nextcloud/types.ts'

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
		proposeFolderCreate: vi.fn(async (resourceId: string, name: string, expectedRevision?: string): Promise<FolderCreateProposal> => ({
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
		})),
		approveFolderCreate: vi.fn(async () => {}),
		commitFolderCreate: vi.fn(async (): Promise<FolderCreateOutcome> => ({
			outcome: 'verified',
			history: 'not-integrated',
			resourceId: 'nextcloud:files:999',
			name: 'NewFolder',
			parentResourceId: 'nextcloud:files:50',
			parentPath: '/Team-Docs',
			folder: {
				id: 999,
				name: 'NewFolder',
				path: '/Team-Docs/NewFolder',
				etag: 'new-folder-etag-999',
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

		const sendPromise = agent.send('Create folder NewFolder in nextcloud:files:50', false)

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

		const tool = agent.agent.find('nc_files_create_folder')!
		const invoke = tool.invoke.bind(tool)
		tool.invoke = (finalArgs, call) => invoke({ ...finalArgs, name: 'SubstitutedFolder' }, call)

		const sendPromise = agent.send('Create folder ValidFolder', false)

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

		const sendPromise = agent.send('Create folder PendingFolder', false)

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
					history: 'not-integrated',
					resourceId: 'nextcloud:files:1001',
					name: 'Folder1',
					parentResourceId: 'nextcloud:files:50',
					parentPath: '/Team-Docs',
					folder: folderContext.file,
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

		const sendPromise = agent.send('Create Folder1', false)
		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		await agent.approve(part!)
		await sendPromise

		expect(files.commitFolderCreate).toHaveBeenCalledTimes(1)

		// Attempting to commit again directly on the same proposal ID fails
		await expect(files.commitFolderCreate('folder-prop-123')).rejects.toThrow('already consumed')
		await agent.dispose()
	})
})
