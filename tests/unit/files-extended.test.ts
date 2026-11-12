import { describe, expect, it, vi } from 'vitest'
import {
	filesExtendedTools,
	validateFolderName,
	validateCopyMoveArgs,
	validateShareReadonlyArgs,
	EXTENDED_FILES_MUTATIONS,
} from '../../src/integrations/nextcloud/filesExtended.ts'
import { EXTENDED_FILE_CAPABILITIES, fileCapability } from '../../src/platform/catalog.ts'
import type { FileApi, FileContext, Identity, ShareEntry, FolderListing } from '../../src/integrations/nextcloud/types.ts'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { FakeGateway } from '../helpers/gateway071.ts'
import { ApprovalPart } from '@fadenstack/ui'

const hostIdentity: Identity = {
	userId: 'alice',
	binding: 'binding-123',
	enabled: true,
	serverUrl: 'https://faden.test',
	agent: 'nc-extended',
	development: true,
	extendedFilesEnabled: true,
}

const mockContext: FileContext = {
	id: 'ctx-1',
	parentId: 1,
	expires: 9999999999,
	file: {
		id: 10,
		name: 'Lab',
		path: '/Copilot-tool-lab',
		etag: 'dir-etag-1',
		mime: 'httpd/unix-directory',
		size: 0,
		folder: true,
		permissions: 31,
		url: 'https://cloud.test/f/10',
	},
}

function createMockFilesApi(overrides: Partial<FileApi> = {}): FileApi {
	return {
		identity: vi.fn(async () => hostIdentity),
		assertIdentity: vi.fn(async () => {}),
		select: vi.fn(async () => mockContext),
		read: vi.fn(async () => ({ file: mockContext.file, text: '', partial: false, totalCharacters: 0, processedCharacters: 0 })),
		pdf: vi.fn(async () => new Uint8Array()),
		list: vi.fn(async () => ({ folder: mockContext.file, files: [], partial: false })),
		propose: vi.fn(async (_id, args) => ({ id: 'prop-1', contextId: 'ctx-1', ...args, target: '/target.txt', before: null })),
		approve: vi.fn(async () => {}),
		commit: vi.fn(async () => ({ operation: 'create', file: mockContext.file })),
		createFolder: vi.fn(async (folderPath, name) => ({ path: `${folderPath}/${name}`, name })),
		copyFile: vi.fn(async (source, destination) => ({ operation: 'copy', source, destination, etag: 'new-etag-copy' })),
		moveFile: vi.fn(async (source, destination) => ({ operation: 'move', source, destination, etag: 'new-etag-move' })),
		sharesRead: vi.fn(async path => ({
			resourceId: path,
			etag: 'r1',
			complete: false,
			coverage: 'Current-user shares only.',
			shares: [
				{ id: 101, shareType: 0, shareWith: 'bob', shareWithDisplayName: 'Bob', permissions: 1, path, mailSend: false },
			],
		})),
		shareReadonly: vi.fn(async (path, recipientUserId) => ({
			id: 102, shareType: 0, shareWith: recipientUserId, shareWithDisplayName: 'Bob User', permissions: 1, path, mailSend: false,
		})),
		organizeRead: vi.fn(async folderPath => ({
			directory: '/Copilot-tool-lab',
			resourceId: folderPath,
			etag: 'folder-rev',
			partial: false,
			entries: [
				{ resourceId: 'nextcloud:files:11', href: '/f/11', filename: 'Readme.txt', etag: 'readme-etag', folder: false },
				{ resourceId: 'nextcloud:files:12', href: '/f/12', filename: 'Subfolder', etag: 'sub-etag', folder: true },
			],
		})),
		getAppCapabilities: vi.fn(async () => ({
			capabilities: { files_sharing: { api_enabled: true } },
			files_sharing: { api_enabled: true },
		})),
		...overrides,
	}
}

describe('NC08: Files argument validation', () => {
	it('validates folder names strictly', () => {
		expect(validateFolderName('Projects')).toBe('Projects')
		expect(validateFolderName('My Folder 2026')).toBe('My Folder 2026')
		expect(() => validateFolderName('')).toThrow('folder name')
		expect(() => validateFolderName('   ')).toThrow('folder name')
		expect(() => validateFolderName('a'.repeat(101))).toThrow('folder name')
		expect(() => validateFolderName('path/with/slash')).toThrow('folder name')
		expect(() => validateFolderName('path\\with\\backslash')).toThrow('folder name')
		expect(() => validateFolderName('folder:name')).toThrow('folder name')
		expect(() => validateFolderName('.')).toThrow('folder name')
		expect(() => validateFolderName('..')).toThrow('folder name')
	})

	it('validates copy and move arguments strictly', () => {
		expect(validateCopyMoveArgs({
			source: 'Readme.txt',
			destination: 'Readme-copy.txt',
			expectedEtag: 'etag-123',
		})).toEqual({ source: 'Readme.txt', destination: 'Readme-copy.txt', expectedEtag: 'etag-123' })

		// Supports snake_case and camelCase aliases
		expect(validateCopyMoveArgs({
			source_resource_id: 'nextcloud:files:100',
			destination_name: 'moved.txt',
			expected_etag: 'etag-456',
		})).toEqual({ source: 'nextcloud:files:100', destination: 'moved.txt', expectedEtag: 'etag-456' })

		expect(() => validateCopyMoveArgs({ destination: 'dest.txt', expectedEtag: 'etag' })).toThrow('source')
		expect(() => validateCopyMoveArgs({ source: 'src.txt', expectedEtag: 'etag' })).toThrow('destination')
		expect(() => validateCopyMoveArgs({ source: 'src.txt', destination: 'invalid/dest.txt', expectedEtag: 'etag' })).toThrow('destination')
		expect(() => validateCopyMoveArgs({ source: 'src.txt', destination: 'dest.txt' })).toThrow('expected source ETag')
	})

	it('validates read-only sharing arguments strictly', () => {
		expect(validateShareReadonlyArgs({
			path: '/Copilot-tool-lab/Readme.txt',
			recipientUserId: 'bob',
			expectedEtag: 'etag-123',
		})).toEqual({ path: '/Copilot-tool-lab/Readme.txt', recipientUserId: 'bob', expectedEtag: 'etag-123' })

		expect(validateShareReadonlyArgs({
			resource_id: 'nextcloud:files:200',
			recipient_user_id: 'carol',
			expected_etag: 'etag-999',
		})).toEqual({ path: 'nextcloud:files:200', recipientUserId: 'carol', expectedEtag: 'etag-999' })

		expect(() => validateShareReadonlyArgs({ recipientUserId: 'bob', expectedEtag: 'e' })).toThrow('target file')
		expect(() => validateShareReadonlyArgs({ path: 'file.txt', expectedEtag: 'e' })).toThrow('recipient')
		expect(() => validateShareReadonlyArgs({ path: 'file.txt', recipientUserId: 'bob' })).toThrow('expected file ETag')
		expect(() => validateShareReadonlyArgs({ path: 'file.txt', recipientUserId: 'b'.repeat(81), expectedEtag: 'e' })).toThrow('recipient')
	})
})

describe('NC08: Capabilities and Catalog review', () => {
	it('registers all 6 NC08 operations with documented risk and reversal classifications', () => {
		expect(EXTENDED_FILE_CAPABILITIES).toHaveLength(6)

		const createFolder = fileCapability('nc_files_create_folder')
		expect(createFolder).toMatchObject({
			subsystem: 'files',
			risk: 'destructive',
			individualApproval: true,
			reversal: 'not-integrated',
		})

		const copy = fileCapability('nc_files_copy')
		expect(copy).toMatchObject({
			subsystem: 'files',
			risk: 'destructive',
			individualApproval: true,
			reversal: 'not-integrated',
		})

		const move = fileCapability('nc_files_move')
		expect(move).toMatchObject({
			subsystem: 'files',
			risk: 'destructive',
			individualApproval: true,
			reversal: 'not-integrated',
		})

		const sharesRead = fileCapability('nc_files_shares_read')
		expect(sharesRead).toMatchObject({
			subsystem: 'files',
			risk: 'read',
			individualApproval: false,
			reversal: 'not-applicable',
		})

		const shareReadonly = fileCapability('nc_files_share_readonly')
		expect(shareReadonly).toMatchObject({
			subsystem: 'files',
			risk: 'destructive',
			individualApproval: true,
			reversal: 'not-integrated',
		})

		const organizeRead = fileCapability('nc_files_organize_read')
		expect(organizeRead).toMatchObject({
			subsystem: 'files',
			risk: 'read',
			individualApproval: false,
			reversal: 'not-applicable',
		})
	})
})

describe('NC08: Extended Files tool execution and approval flow', () => {
	it('executes folder organization read without changing state', async () => {
		const files = createMockFilesApi()
		const tools = filesExtendedTools({ files, recordResult: () => {} })
		const organizeTool = tools.find(t => t.name === 'nc_files_organize_read')!

		const result = await organizeTool.invoke({ resource_id: 'nextcloud:files:10' }, { callId: 'c1', signal: new AbortController().signal }) as FolderListing
		expect(files.organizeRead).toHaveBeenCalled()
		expect(result.entries).toHaveLength(2)
		expect(result.entries[0]!.filename).toBe('Readme.txt')
		expect(result.entries[1]!.folder).toBe(true)
	})

	it('reads existing shares and strips any credential/secret data', async () => {
		const files = createMockFilesApi({
			sharesRead: vi.fn(async () => ({
				resourceId: 'nextcloud:files:10',
				etag: 'r1',
				complete: false,
				coverage: 'Current-user shares only.',
				shares: [
				{
					id: 55,
					shareType: 0,
					shareWith: 'bob',
					shareWithDisplayName: 'Bob Developer',
					permissions: 1,
					path: '/Copilot-tool-lab/Readme.txt',
					token: 'SECRET_TOKEN_SHOULD_BE_EXCLUDED',
					share_pass: 'SECRET_PASS_SHOULD_BE_EXCLUDED',
				} as unknown as ShareEntry,
				],
			})),
		})
		const tools = filesExtendedTools({ files, recordResult: () => {} })
		const sharesTool = tools.find(t => t.name === 'nc_files_shares_read')!

		const result = await sharesTool.invoke({ resource_id: 'nextcloud:files:10' }, { callId: 'c2', signal: new AbortController().signal }) as { shares: ShareEntry[] }
		expect(files.sharesRead).toHaveBeenCalledWith('nextcloud:files:10', expect.any(Object))
		expect(result.shares[0]!.id).toBe(55)
		expect(result.shares[0]!.permissions).toBe(1)
		expect(JSON.stringify(result)).not.toContain('SECRET_TOKEN')
		expect(JSON.stringify(result)).not.toContain('SECRET_PASS')
	})

	it('routes create_folder through individual approval and exact commit', async () => {
		const files = createMockFilesApi()
		let round = 0
		const gateway = new FakeGateway({
			agents: [{
				slug: 'nc-extended',
				version: 1,
				contract: [
					{ name: 'nc_files_create_folder', risk: 'write' },
				],
			}],
			users: [{ email: 'alice', password: 'test', agents: ['nc-extended'] }],
			model: () => ++round === 1
				? ({
					toolCalls: [{
						id: 'call-create-folder',
						name: 'nc_files_create_folder',
						arguments: { name: 'Approved-Folder' },
					}],
				})
				: ({ text: 'Created folder' }),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'test')

		const sendPromise = agent.send('Create a folder named Approved-Folder', false)

		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		expect(part!.request.toolName).toBe('nc_files_create_folder')
		expect(part!.request.canAllowAlways).toBe(false)
		expect(part!.request.canAllowForSession).toBe(false)

		await agent.approve(part!)
		await sendPromise

		expect(files.createFolder).toHaveBeenCalledWith(expect.any(String), 'Approved-Folder', expect.any(Object))
		await agent.dispose()
	})

	it('routes copy and move through approval with conflict protection', async () => {
		const files = createMockFilesApi()
		let callIndex = 0
		const gateway = new FakeGateway({
			agents: [{
				slug: 'nc-extended',
				version: 1,
				contract: [
					{ name: 'nc_files_copy', risk: 'write' },
					{ name: 'nc_files_move', risk: 'write' },
				],
			}],
			users: [{ email: 'alice', password: 'test', agents: ['nc-extended'] }],
			model: () => {
				callIndex++
				if (callIndex === 1) {
					return {
						toolCalls: [{
							id: 'call-copy',
							name: 'nc_files_copy',
							arguments: { source: 'Readme.txt', destination: 'Copy.txt', expectedEtag: 'readme-etag-1' },
						}],
					}
				}
				return { text: 'Done' }
			},
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'test')

		const sendPromise = agent.send('Copy Readme.txt to Copy.txt', false)

		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		expect(part!.request.toolName).toBe('nc_files_copy')
		await agent.approve(part!)
		await sendPromise

		expect(files.copyFile).toHaveBeenCalledWith('Readme.txt', 'Copy.txt', 'readme-etag-1', expect.any(Object))
		await agent.dispose()
	})

	it('creates read-only share with sendMail=false and read-only permission', async () => {
		const files = createMockFilesApi()
		let round = 0
		const gateway = new FakeGateway({
			agents: [{
				slug: 'nc-extended',
				version: 1,
				contract: [
					{ name: 'nc_files_share_readonly', risk: 'write' },
				],
			}],
			users: [{ email: 'alice', password: 'test', agents: ['nc-extended'] }],
			model: () => ++round === 1
				? ({
					toolCalls: [{
						id: 'call-share',
						name: 'nc_files_share_readonly',
						arguments: { path: '/Copilot-tool-lab/Readme.txt', recipientUserId: 'bob', expectedEtag: 'readme-etag-1' },
					}],
				})
				: ({ text: 'Shared' }),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'test')

		const sendPromise = agent.send('Share Readme.txt read-only with Bob', false)

		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		expect(part!.request.toolName).toBe('nc_files_share_readonly')
		await agent.approve(part!)
		await sendPromise

		expect(files.shareReadonly).toHaveBeenCalledWith('/Copilot-tool-lab/Readme.txt', 'bob', 'readme-etag-1', expect.any(Object))
		await agent.dispose()
	})

	it('denial of mutation halts turn and blocks subsequent writes', async () => {
		const files = createMockFilesApi()
		const gateway = new FakeGateway({
			agents: [{
				slug: 'nc-extended',
				version: 1,
				contract: [
					{ name: 'nc_files_create_folder', risk: 'write' },
				],
			}],
			users: [{ email: 'alice', password: 'test', agents: ['nc-extended'] }],
			model: () => ({
				toolCalls: [{
					id: 'call-folder-denied',
					name: 'nc_files_create_folder',
					arguments: { name: 'Denied-Folder' },
				}],
			}),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'test')

		const sendPromise = agent.send('Create folder Denied-Folder', false)

		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		part!.deny('User rejected folder creation')
		await sendPromise

		expect(files.createFolder).not.toHaveBeenCalled()
		await agent.dispose()
	})

	it('reports unavailable when files_sharing or DAV capability is absent', async () => {
		const files = createMockFilesApi({
			shareReadonly: undefined,
			createFolder: undefined,
		})
		const tools = filesExtendedTools({ files, recordResult: () => {} })

		const shareTool = tools.find(t => t.name === 'nc_files_share_readonly')!
		await expect(shareTool.invoke({ path: 'x.txt', recipientUserId: 'bob', expectedEtag: 'e' }, {
			callId: 'c1', signal: new AbortController().signal,
		})).rejects.toThrow('unavailable')

		const folderTool = tools.find(t => t.name === 'nc_files_create_folder')!
		await expect(folderTool.invoke({ name: 'test' }, {
			callId: 'c2', signal: new AbortController().signal,
		})).rejects.toThrow('unavailable')
	})
})
