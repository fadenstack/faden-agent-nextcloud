import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { FakeGateway } from '../helpers/gateway071.ts'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { NextcloudFiles } from '../../src/integrations/nextcloud/files.ts'
import { filesReadTools, type ExtendedFilesHost } from '../../src/integrations/nextcloud/filesExtended.ts'
import { TurnScope, type ScopePolicy, type ScopeIdentity } from '../../src/platform/scope.ts'
import { ScopeViolation } from '../../src/platform/limits.ts'
import type { FileApi, FileContext, Identity, ShareEntry, ShareListing, FolderListing } from '../../src/integrations/nextcloud/types.ts'

const readPolicy: ScopePolicy = {
	version: 'native-reads-v1',
	gatewayOrigin: 'https://faden.test',
	sources: [{ kind: 'user-files' }],
	destinations: [],
	tools: ['nc_files_organize_read', 'nc_files_shares_read'],
	sourceClasses: ['standard'],
	downstreamPolicy: 'unverified',
	destinationAudiences: ['private'],
	movements: [],
}
const shareListing = (shares: ShareEntry[], resourceId = 'nextcloud:files:101'): ShareListing => ({ resourceId, etag: 'r1', shares, complete: false, coverage: 'Current-user shares only.' })

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn(), request: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path: string) => `https://cloud.test${path}`,
	generateRemoteUrl: (name: string) => `https://cloud.test/remote.php/${name}/`,
}))

const fileContext: FileContext = {
	id: 'ctx-file',
	parentId: 10,
	expires: 9999999999,
	file: {
		id: 101,
		name: 'Contract.md',
		path: '/Projects/Contract.md',
		etag: 'file-etag-1',
		mime: 'text/markdown',
		size: 2048,
		folder: false,
		permissions: 31,
		url: 'https://cloud.test/f/101',
	},
}

const folderContext: FileContext = {
	id: 'ctx-folder',
	parentId: 1,
	expires: 9999999999,
	file: {
		id: 202,
		name: 'Projects',
		path: '/Projects',
		etag: 'folder-etag-1',
		mime: 'httpd/unix-directory',
		size: 0,
		folder: true,
		permissions: 31,
		url: 'https://cloud.test/f/202',
	},
}

function mockFilesApi(overrides: Partial<FileApi> = {}): FileApi {
	return {
		identity: vi.fn(async () => ({
			userId: 'alice',
			binding: 'session-xyz',
			enabled: true,
			serverUrl: 'https://faden.test',
			agent: 'nc-agent',
			development: false,
			filesReadsEnabled: true,
		})),
		assertIdentity: vi.fn(async () => {}),
		select: vi.fn(async () => folderContext),
		read: vi.fn(async () => ({ file: fileContext.file, text: 'content', partial: false, totalCharacters: 7, processedCharacters: 7 })),
		pdf: vi.fn(async () => new Uint8Array()),
		list: vi.fn(async () => ({ folder: folderContext.file, files: [fileContext.file], partial: false })),
		propose: vi.fn(async () => ({ id: 'p1', target: '', before: null, contextId: 'c1', operation: 'create', content: '' })),
		approve: vi.fn(async () => {}),
		commit: vi.fn(async () => ({ operation: 'create', file: fileContext.file })),
		organizeRead: vi.fn(async (resourceId, expectedRevision) => ({
			directory: '/Projects',
			resourceId: resourceId ?? 'nextcloud:files:202',
			etag: typeof expectedRevision === 'string' ? expectedRevision : 'folder-etag-1',
			entries: [
				{ resourceId: 'nextcloud:files:101', filename: 'Contract.md', etag: 'file-etag-1', folder: false, size: 2048, modified: 1700000000, permissions: 31, href: 'https://cloud.test/f/101' },
				{ resourceId: 'nextcloud:files:203', filename: 'Subfolder', etag: 'sub-etag', folder: true, size: 0, modified: 1700000000, permissions: 31, href: 'https://cloud.test/f/203' },
			],
			partial: false,
		})),
		sharesRead: vi.fn(async resourceId => shareListing([
			{
				id: 1,
				shareType: 0,
				shareWith: 'bob',
				shareWithDisplayName: 'Bob Developer',
				permissions: 1,
				path: '/Projects/Contract.md',
				fileTarget: 'Contract.md',
				expiration: null,
				mailSend: false,
				inherited: false,
			},
			{
				id: 2,
				shareType: 3,
				shareWith: 'public-link',
				shareWithDisplayName: 'Public link',
				permissions: 1,
				path: '/Projects/Contract.md',
				fileTarget: 'Contract.md',
				expiration: '2026-12-31T23:59:59Z',
				mailSend: false,
				inherited: true,
			},
		], resourceId)),
		...overrides,
	}
}

describe('NC08 native read-only Files integration: argument validation & reference resolution', () => {
	beforeEach(() => vi.resetAllMocks())

	it('nc_files_organize_read requires canonical nextcloud:files:ID and rejects paths and URLs', async () => {
		const files = mockFilesApi()
		const tools = filesReadTools({ files, recordResult: () => {} })
		const tool = tools.find(t => t.name === 'nc_files_organize_read')!

		await expect(tool.invoke({ resource_id: '/Projects' }, { callId: 'c1', signal: new AbortController().signal }))
			.rejects.toThrow('Use a Nextcloud file resource ID, not a path or URL.')

		await expect(tool.invoke({ resource_id: 'https://cloud.test/f/202' }, { callId: 'c2', signal: new AbortController().signal }))
			.rejects.toThrow('Use a Nextcloud file resource ID, not a path or URL.')

		await expect(tool.invoke({ resource_id: '../parent' }, { callId: 'c3', signal: new AbortController().signal }))
			.rejects.toThrow('Use a Nextcloud file resource ID, not a path or URL.')

		const result = await tool.invoke({ resource_id: 'nextcloud:files:202' }, { callId: 'c4', signal: new AbortController().signal }) as FolderListing
		expect(files.organizeRead).toHaveBeenCalledWith('nextcloud:files:202', expect.any(Object))
		expect(result.resourceId).toBe('nextcloud:files:202')
	})

	it('nc_files_organize_read with selected file context does not silently grant parent/root access', async () => {
		const files = mockFilesApi()
		const host: ExtendedFilesHost = {
			files,
			context: () => fileContext, // Active context is a FILE, not a folder
			recordResult: () => {},
		}
		const tools = filesReadTools(host)
		const tool = tools.find(t => t.name === 'nc_files_organize_read')!

		await expect(tool.invoke({}, { callId: 'c1', signal: new AbortController().signal }))
			.rejects.toThrow('Folder organization read requires a folder context or folder resource_id, not a selected file.')

		expect(files.organizeRead).not.toHaveBeenCalled()
	})

	it('nc_files_organize_read with folder context resolves canonical reference automatically', async () => {
		const files = mockFilesApi()
		const authorizeSource = vi.fn(async () => {})
		const rememberSource = vi.fn(async () => {})
		const host: ExtendedFilesHost = {
			files,
			context: () => folderContext,
			recordResult: () => {},
			authorizeSource,
			rememberSource,
		}
		const tools = filesReadTools(host)
		const tool = tools.find(t => t.name === 'nc_files_organize_read')!

		const result = await tool.invoke({}, { callId: 'c1', signal: new AbortController().signal }) as FolderListing
		expect(authorizeSource).toHaveBeenCalledWith('nextcloud:files:202', expect.any(Object), 'read')
		expect(files.organizeRead).toHaveBeenCalledWith('nextcloud:files:202', expect.any(Object))
		expect(rememberSource).toHaveBeenCalledWith('nextcloud:files:101', expect.any(Object))
		expect(rememberSource).toHaveBeenCalledWith('nextcloud:files:203', expect.any(Object))
		expect(result.entries).toHaveLength(2)
	})

	it('nc_files_shares_read accepts canonical reference and rejects arbitrary paths and URLs', async () => {
		const files = mockFilesApi()
		const authorizeSource = vi.fn(async () => {})
		const tools = filesReadTools({ files, recordResult: () => {}, authorizeSource })
		const tool = tools.find(t => t.name === 'nc_files_shares_read')!

		await expect(tool.invoke({ resource_id: '/some/unauthorized/path' }, { callId: 'c1', signal: new AbortController().signal }))
			.rejects.toThrow('Use a Nextcloud file resource ID, not a path or URL.')

		await expect(tool.invoke({ resource_id: 'https://cloud.test/f/101' }, { callId: 'c2', signal: new AbortController().signal }))
			.rejects.toThrow('Use a Nextcloud file resource ID, not a path or URL.')

		const result = await tool.invoke({ resource_id: 'nextcloud:files:101' }, { callId: 'c3', signal: new AbortController().signal }) as ShareListing
		expect(authorizeSource).toHaveBeenCalledWith('nextcloud:files:101', expect.any(Object), 'shares')
		expect(files.sharesRead).toHaveBeenCalledWith('nextcloud:files:101', expect.any(Object))
		expect(result.shares).toHaveLength(2)
		expect(result.complete).toBe(false)
	})

	it('nc_files_shares_read strips reusable tokens, passwords, and token links', async () => {
		const files = mockFilesApi({
			sharesRead: vi.fn(async () => shareListing([
				{
					id: 10,
					shareType: 3, // Link share
					shareWith: 'secret-token-12345',
					shareWithDisplayName: 'Link share with token',
					permissions: 1,
					path: '/Projects/Contract.md',
					token: 'LEAKED_TOKEN',
					password: 'LEAKED_PASSWORD',
					url: 'https://cloud.test/s/LEAKED_TOKEN',
					mailSend: false,
					inherited: true,
				} as unknown as ShareEntry,
			])),
		})
		const tools = filesReadTools({ files, recordResult: () => {} })
		const tool = tools.find(t => t.name === 'nc_files_shares_read')!

		const result = await tool.invoke({ resource_id: 'nextcloud:files:101' }, { callId: 'c1', signal: new AbortController().signal }) as ShareListing
		expect(result.shares).toHaveLength(1)
		const share = result.shares[0]!
		expect(share.id).toBe(10)
		expect(share.shareType).toBe(3)
		expect(share.inherited).toBe(true)
		// Secret stripping verification
		expect(share.shareWith).toBe('public-link')
		expect(JSON.stringify(result)).not.toContain('secret-token-12345')
		expect(JSON.stringify(result)).not.toContain('LEAKED_TOKEN')
		expect(JSON.stringify(result)).not.toContain('LEAKED_PASSWORD')
		expect((share as Record<string, unknown>).token).toBeUndefined()
		expect((share as Record<string, unknown>).password).toBeUndefined()
		expect((share as Record<string, unknown>).url).toBeUndefined()
	})

	it('forwards expectedRevision for optimistic concurrency check', async () => {
		const files = mockFilesApi()
		const tools = filesReadTools({ files, recordResult: () => {} })
		const organizeTool = tools.find(t => t.name === 'nc_files_organize_read')!
		const sharesTool = tools.find(t => t.name === 'nc_files_shares_read')!

		await organizeTool.invoke({ resource_id: 'nextcloud:files:202', expectedRevision: 'rev-abc' }, { callId: 'c1', signal: new AbortController().signal })
		expect(files.organizeRead).toHaveBeenCalledWith('nextcloud:files:202', 'rev-abc', expect.any(Object))

		await sharesTool.invoke({ resource_id: 'nextcloud:files:101', expectedRevision: 'rev-def' }, { callId: 'c2', signal: new AbortController().signal })
		expect(files.sharesRead).toHaveBeenCalledWith('nextcloud:files:101', 'rev-def', expect.any(Object))
	})

	it('refuses missing context, legacy names, null references and malformed revisions before transport', async () => {
		const files = mockFilesApi()
		for (const tool of filesReadTools({ files, recordResult: () => {} })) {
			for (const args of [{}, { resource_id: null }, { resource_id: 'Contract.md' }, { filename: 'Contract.md' }, { resource_id: 'nextcloud:files:101', expectedRevision: 42 }]) {
				await expect(tool.invoke(args, { callId: 'invalid', signal: new AbortController().signal })).rejects.toThrow()
			}
		}
		expect(files.organizeRead).not.toHaveBeenCalled()
		expect(files.sharesRead).not.toHaveBeenCalled()
	})

	it('propagates Stop before dispatch and rejects a late result before binding or disclosure', async () => {
		const files = mockFilesApi()
		const stop = new AbortController()
		stop.abort()
		for (const tool of filesReadTools({ files, recordResult: () => {} })) {
			await expect(tool.invoke({ resource_id: 'nextcloud:files:202' }, { callId: 'stop', signal: stop.signal })).rejects.toThrow()
		}
		expect(files.organizeRead).not.toHaveBeenCalled()
		expect(files.sharesRead).not.toHaveBeenCalled()
		const late = new AbortController()
		const original = files.organizeRead!
		files.organizeRead = vi.fn(async (...args) => { const result = await original(...args); late.abort(); return result })
		const rememberSource = vi.fn()
		await expect(filesReadTools({ files, recordResult: () => {}, rememberSource })[0]!.invoke({ resource_id: 'nextcloud:files:202' }, { callId: 'late', signal: late.signal })).rejects.toThrow()
		expect(rememberSource).not.toHaveBeenCalled()
	})

	it('refuses unauthorized known references before invoking the native read adapter', async () => {
		const files = mockFilesApi()
		const authorizeSource = vi.fn(async () => { throw new Error('Unknown scoped reference') })
		for (const tool of filesReadTools({ files, recordResult: () => {}, authorizeSource })) {
			await expect(tool.invoke({ resource_id: 'nextcloud:files:202' }, { callId: 'unknown', signal: new AbortController().signal })).rejects.toThrow('Unknown scoped reference')
		}
		expect(files.organizeRead).not.toHaveBeenCalled()
		expect(files.sharesRead).not.toHaveBeenCalled()
	})

	it('rejects a share envelope for another resource and malformed native numeric fields', async () => {
		const files = mockFilesApi({ sharesRead: vi.fn(async () => shareListing([], 'nextcloud:files:999')) })
		const tool = filesReadTools({ files, recordResult: () => {} })[1]!
		await expect(tool.invoke({ resource_id: 'nextcloud:files:101' }, { callId: 'wrong', signal: new AbortController().signal })).rejects.toThrow('Invalid native share response')
		vi.mocked(files.sharesRead!).mockResolvedValue(shareListing([{ id: NaN, shareType: 3, permissions: 1, path: '/file' }]))
		await expect(tool.invoke({ resource_id: 'nextcloud:files:101' }, { callId: 'bad', signal: new AbortController().signal })).rejects.toThrow('Invalid native share metadata')
	})
})

describe('NC08 server-controlled read opt-in and tool offering', () => {
	it('registers nc_files_organize_read and nc_files_shares_read when filesReadsEnabled is true without experimental mode', async () => {
		const files = mockFilesApi()
		const gateway = new FakeGateway({
			agents: [{
				slug: 'nc-agent',
				version: 1,
				contract: [
					{ name: 'nc_files_organize_read', risk: 'read' },
					{ name: 'nc_files_shares_read', risk: 'read' },
					{ name: 'nc_files_create_folder', risk: 'destructive' },
				],
			}],
			users: [{ email: 'alice', password: 'test', agents: ['nc-agent'] }],
		})
		const config: Identity = {
			userId: 'alice',
			binding: 'session-xyz',
			enabled: true,
			serverUrl: 'https://faden.test',
			agent: 'nc-agent',
			development: false, // NOT development / NOT experimental!
			filesReadsEnabled: true, // Server-controlled opt-in enabled!
			scopePolicy: readPolicy,
			extendedFilesEnabled: true, // Should remain gated because development=false
		}
		const host = new NextcloudAgent(config, files, gateway.fetch)
		await host.signIn('alice', 'test')

		// Read tools are registered and available
		expect(host.agent.find('nc_files_organize_read')).toBeDefined()
		expect(host.agent.find('nc_files_shares_read')).toBeDefined()

		// Mutation tools REMAIN ABSENT (gated behind experimentalAdaptersAllowed)
		expect(host.agent.find('nc_files_create_folder')).toBeUndefined()
		expect(host.agent.find('nc_files_copy')).toBeUndefined()
		expect(host.agent.find('nc_files_move')).toBeUndefined()
		expect(host.agent.find('nc_files_share_readonly')).toBeUndefined()
		await host.dispose()
	})

	it('leaves nc_files_organize_read and nc_files_shares_read absent when filesReadsEnabled is false in non-experimental mode', async () => {
		const files = mockFilesApi()
		const gateway = new FakeGateway({
			agents: [{
				slug: 'nc-agent',
				version: 1,
				contract: [
					{ name: 'nc_files_organize_read', risk: 'read' },
					{ name: 'nc_files_shares_read', risk: 'read' },
					{ name: 'nc_files_create_folder', risk: 'destructive' },
				],
			}],
			users: [{ email: 'alice', password: 'test', agents: ['nc-agent'] }],
		})
		const config: Identity = {
			userId: 'alice',
			binding: 'session-xyz',
			enabled: true,
			serverUrl: 'https://faden.test',
			agent: 'nc-agent',
			development: false,
			filesReadsEnabled: false,
		}
		const host = new NextcloudAgent(config, files, gateway.fetch)
		await host.signIn('alice', 'test')

		expect(host.agent.find('nc_files_organize_read')).toBeUndefined()
		expect(host.agent.find('nc_files_shares_read')).toBeUndefined()
		expect(host.agent.find('nc_files_create_folder')).toBeUndefined()
		await host.dispose()
	})

	it('filters nc_files_organize_read when scope policy sources only grant file access', () => {
		const policy: ScopePolicy = {
			version: 'pol-1',
			gatewayOrigin: 'https://faden.test',
			developmentLoopback: false,
			sources: [{ kind: 'file', resourceId: 'nextcloud:files:101' }],
			destinations: [{ kind: 'file', resourceId: 'nextcloud:files:101' }],
			tools: ['nc_read_file', 'nc_files_organize_read', 'nc_files_shares_read'],
			sourceClasses: ['standard'],
			downstreamPolicy: 'unverified',
			destinationAudiences: ['private'],
			movements: [{ source: 'standard', destination: 'private' }],
		}
		const published = [
			{ name: 'nc_read_file', risk: 'read' as const },
			{ name: 'nc_files_organize_read', risk: 'read' as const },
			{ name: 'nc_files_shares_read', risk: 'read' as const },
		]
		const identity: ScopeIdentity = {
			nextcloudOrigin: 'https://cloud.test',
			nextcloudUser: 'alice',
			nextcloudBinding: 'binding-1',
			fadenstackOrigin: 'https://faden.test',
			fadenstackPrincipal: { kind: 'subject', id: 'alice' },
			agent: 'nc-agent',
			agentVersion: 1,
			schemaVersion: 'schema-1',
			contractVersion: 'contract-1',
			policyVersion: 'pol-1',
		}
		const guard = new TurnScope(
			identity,
			policy,
			published,
			() => identity,
			vi.fn(async () => ({ resourceId: 'nextcloud:files:101', revision: 'r1', audience: 'private', sourceClass: 'standard' })),
		)

		// nc_files_shares_read is offered on file sources
		expect(guard.offeredTools).toContain('nc_files_shares_read')
		expect(guard.offeredTools).toContain('nc_read_file')
		// nc_files_organize_read is NOT offered on file-only sources
		expect(guard.offeredTools).not.toContain('nc_files_organize_read')

		// Attempting to dispatch nc_files_organize_read throws ScopeViolation
		expect(() => guard.beginTool('nc_files_organize_read')).toThrow(ScopeViolation)
	})
})

describe('NextcloudFiles native HTTP transport for organizeRead and sharesRead', () => {
	beforeEach(() => vi.resetAllMocks())

	it('organizeRead posts to /files/organize with resourceId and expectedRevision', async () => {
		const initial: Identity = {
			userId: 'alice',
			binding: 'session-xyz',
			enabled: true,
			serverUrl: 'https://faden.test',
			agent: 'nc-agent',
			development: false,
			filesReadsEnabled: true,
		}
		vi.mocked(axios.get).mockResolvedValueOnce({ data: initial } as never)
		vi.mocked(axios.post).mockResolvedValueOnce({
			data: {
				directory: '/Projects',
				resourceId: 'nextcloud:files:202',
				etag: 'folder-etag-1',
				entries: [],
				partial: false,
			},
		} as never)

		const files = new NextcloudFiles(initial)
		const listing = await files.organizeRead('nextcloud:files:202', 'folder-etag-1')

		expect(axios.post).toHaveBeenCalledWith(
			'https://cloud.test/apps/fadenstack_agent/files/organize',
			{ resourceId: 'nextcloud:files:202', expectedRevision: 'folder-etag-1' },
			expect.objectContaining({
				headers: { 'X-Fadenstack-Session': 'session-xyz' },
			}),
		)
		expect(listing.resourceId).toBe('nextcloud:files:202')
	})

	it('sharesRead posts to /files/shares with resourceId and expectedRevision', async () => {
		const initial: Identity = {
			userId: 'alice',
			binding: 'session-xyz',
			enabled: true,
			serverUrl: 'https://faden.test',
			agent: 'nc-agent',
			development: false,
			filesReadsEnabled: true,
		}
		vi.mocked(axios.get).mockResolvedValueOnce({ data: initial } as never)
		vi.mocked(axios.post).mockResolvedValueOnce({
			data: shareListing([
				{
					id: 1,
					shareType: 0,
					shareWith: 'bob',
					shareWithDisplayName: 'Bob Developer',
					permissions: 1,
					path: '/Projects/Contract.md',
					fileTarget: 'Contract.md',
					expiration: null,
					mailSend: false,
					inherited: false,
				},
			]),
		} as never)

		const files = new NextcloudFiles(initial)
		const shares = await files.sharesRead('nextcloud:files:101', 'file-etag-1')

		expect(axios.post).toHaveBeenCalledWith(
			'https://cloud.test/apps/fadenstack_agent/files/shares',
			{ resourceId: 'nextcloud:files:101', expectedRevision: 'file-etag-1' },
			expect.objectContaining({
				headers: { 'X-Fadenstack-Session': 'session-xyz' },
			}),
		)
		expect(shares.shares).toHaveLength(1)
		expect(shares.shares[0]!.id).toBe(1)
		expect(shares.complete).toBe(false)
	})
})
