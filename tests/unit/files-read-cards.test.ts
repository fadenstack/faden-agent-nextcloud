import { describe, expect, it, vi } from 'vitest'
import { createApp, markRaw, nextTick } from 'vue'
import { FakeGateway } from '@fadenstack/testing'
import { ToolCallPart } from '@fadenstack/ui'
import AgentChat from '../../src/components/AgentChat.vue'
import FolderResultsCard from '../../src/components/FolderResultsCard.vue'
import SharesResultsCard from '../../src/components/SharesResultsCard.vue'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import type { FileApi, FileContext, Identity, FolderOverviewResults, ShareOverviewResults, ShareEntry } from '../../src/integrations/nextcloud/types.ts'
import type { ScopePolicy } from '../../src/platform/scope.ts'

vi.mock('../../src/components/HeaderButton.vue', () => ({ default: { template: '<button>Fadenstack Agent</button>' } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => new URL(path, location.origin).href, generateRemoteUrl: () => `${location.origin}/remote.php/` }))

const readPolicy: ScopePolicy = {
	version: 'native-reads-v1',
	gatewayOrigin: 'https://faden.test',
	sources: [{ kind: 'user-files' }],
	destinations: [],
	tools: ['nc_files_organize_read', 'nc_files_shares_read', 'nc_get_selected_file'],
	sourceClasses: ['standard'],
	downstreamPolicy: 'unverified',
	destinationAudiences: ['private'],
	movements: [],
	developmentLoopback: true,
}

const identity: Identity = {
	userId: 'alice',
	binding: 'binding',
	enabled: true,
	serverUrl: 'https://faden.test',
	agent: 'nc-reads',
	development: false,
	filesReadsEnabled: true,
	scopePolicy: readPolicy,
}

const selectedFolder: FileContext = {
	id: 'ctx-folder',
	parentId: 1,
	expires: 9999999999,
	file: {
		id: 202,
		name: 'Projects',
		path: '/Projects',
		etag: 'folder-etag',
		mime: 'httpd/unix-directory',
		size: 0,
		folder: true,
		permissions: 31,
		url: 'https://cloud.test/f/202',
	},
}

function fixture(modelFn: ConstructorParameters<typeof FakeGateway>[0]['model'], fileOverrides: Partial<FileApi> = {}) {
	const gateway = new FakeGateway({
		agents: [{
			slug: 'nc-reads',
			version: 1,
			contract: [
				{ name: 'nc_files_organize_read', risk: 'read' },
				{ name: 'nc_files_shares_read', risk: 'read' },
				{ name: 'nc_get_selected_file', risk: 'read' },
			],
		}],
		users: [{ email: 'alice', password: 'test', agents: ['nc-reads'] }],
		model: modelFn,
	})
	const files: FileApi = {
		identity: vi.fn(async () => identity),
		assertIdentity: vi.fn(async () => {}),
		select: vi.fn(async () => selectedFolder),
		scopeResource: vi.fn(async (resourceId: string) => ({
			resourceId,
			revision: 'rev-1',
			sourceClass: 'standard' as const,
			audience: 'private' as const,
			scope: { kind: 'user-files' as const },
		})),
		read: vi.fn(async () => ({ file: selectedFolder.file, text: '', partial: false, totalCharacters: 0, processedCharacters: 0 })),
		organizeRead: vi.fn(async () => ({
			directory: '/Projects',
			resourceId: 'nextcloud:files:202',
			etag: 'folder-etag',
			entries: [
				{ resourceId: 'nextcloud:files:101', href: '/remote.php/dav/files/alice/Projects/Contract.md', filename: 'Contract.md', etag: 'etag1', folder: false, size: 2048, modified: 1791797400 },
				{ resourceId: 'nextcloud:files:203', href: '/remote.php/dav/files/alice/Projects/Archive', filename: 'Archive', etag: 'etag2', folder: true, size: 0, modified: 1791797400 },
			],
			partial: true,
		})),
		sharesRead: vi.fn(async () => ({
			resourceId: 'nextcloud:files:101',
			etag: 'etag1',
			complete: false,
			coverage: 'Current-user user/group/public-link shares only.',
			shares: [
				{ id: 1, shareType: 0, shareWith: 'bob', shareWithDisplayName: 'Bob Pilot', permissions: 1, path: '/Projects/Contract.md', inherited: false },
				{ id: 2, shareType: 1, shareWith: 'dev-team', shareWithDisplayName: 'Development Team', permissions: 31, path: '/Projects/Contract.md', inherited: true },
				{ id: 3, shareType: 3, shareWith: 'public-link', shareWithDisplayName: 'Public link', permissions: 1, path: '/Projects/Contract.md', expiration: '2026-12-31', inherited: false },
			],
		})),
		...fileOverrides,
	}
	const host = new NextcloudAgent(identity, files, gateway.fetch)
	return { gateway, files, host }
}

describe('Typed Files read-result rendering and composed UI path', () => {
	it('rejects a result beyond a narrow native record budget without rendering a card', async () => {
		let round = 0
		const source = fixture(() => ++round === 1 ? { toolCalls: [{ id: 'limited', name: 'nc_files_organize_read', arguments: { resource_id: 'nextcloud:files:202' } }] } : { text: 'Narrow the request.' })
		const host = new NextcloudAgent({ ...identity, scopePolicy: { ...readPolicy, limits: { records: 1 } } }, source.files, source.gateway.fetch)
		await host.signIn('alice', 'test')
		host.offerSelection(await host.selection.select(202))
		const root = document.createElement('div')
		document.body.append(root)
		const app = createApp(AgentChat, { host: markRaw(host) })
		app.mount(root)
		try {
			await host.send('List this folder')
			await nextTick()
			expect(source.files.organizeRead).toHaveBeenCalledOnce()
			expect(root.querySelector('.fdn-folder-results')).toBeNull()
			expect(JSON.stringify(source.gateway.modelCalls)).not.toContain('Contract.md')
		} finally { app.unmount(); root.remove(); await host.dispose(); await source.host.dispose() }
	})
	it('keeps earlier cards unchanged when the model reuses a call ID in a later turn', async () => {
		let reads = 0
		const { host } = fixture(request => request.messages.at(-1)?.role === 'tool' ? { text: 'Listed.' }
			: { toolCalls: [{ id: 'reused-call', name: 'nc_files_organize_read', arguments: { resource_id: 'nextcloud:files:202' } }] }, {
			organizeRead: vi.fn(async () => ({ directory: '/Projects', resourceId: 'nextcloud:files:202', etag: 'folder-rev', partial: false,
				entries: [{ resourceId: `nextcloud:files:${101 + reads}`, filename: ++reads === 1 ? 'first-only.md' : 'second-only.md', folder: false, etag: 'r1', href: '/f/101' }] })),
		})
		await host.signIn('alice', 'test')
		host.offerSelection(await host.selection.select(202))
		const root = document.createElement('div')
		document.body.append(root)
		let app = createApp(AgentChat, { host: markRaw(host) })
		app.mount(root)
		try {
			await host.send('First listing')
			await host.send('Second listing')
			// Reopening the view must reconstruct the earlier card from its own turn.
			app.unmount()
			app = createApp(AgentChat, { host: markRaw(host) })
			app.mount(root)
			await nextTick()
			const cards = root.querySelectorAll('.fdn-folder-results')
			expect(cards).toHaveLength(2)
			expect(cards[0]!.textContent).toContain('first-only.md')
			expect(cards[0]!.textContent).not.toContain('second-only.md')
			expect(cards[1]!.textContent).toContain('second-only.md')
		} finally { app.unmount(); root.remove(); await host.dispose() }
	})
	it('FolderResultsCard renders folder overview with compact rows, icons, native links, and partial note', () => {
		const mockHost = {
			chat: { isBusy: false },
			config: { locale: 'en-US' },
			addSearchContext: vi.fn(async () => {}),
		} as unknown as NextcloudAgent

		const result: FolderOverviewResults = {
			kind: 'nextcloud-folder-overview',
			directory: '/Projects',
			resourceId: 'nextcloud:files:202',
			etag: 'etag-f',
			entries: [
				{ resourceId: 'nextcloud:files:101', href: '/f/101', filename: 'Contract.md', etag: 'e1', folder: false, size: 1048576, modified: 1791797400 },
				{ resourceId: 'nextcloud:files:203', href: '/f/203', filename: 'Subfolder', etag: 'e2', folder: true },
			],
			partial: true,
		}

		const root = document.createElement('div')
		const app = createApp(FolderResultsCard, { result, host: mockHost })
		app.mount(root)

		expect(root.textContent).toContain('Folder contents: /Projects')
		expect(root.textContent).toContain('2 items')
		expect(root.textContent).toContain('Contract.md')
		expect(root.textContent).toContain('1.0 MB')
		expect(root.textContent).toContain('Subfolder')
		expect(root.textContent).toContain('(Folder)')

		// Native /f/ID link check
		const link = root.querySelector<HTMLAnchorElement>('.fdn-folder-entry a')
		expect(link).not.toBeNull()
		expect(link?.getAttribute('href')).toContain('/f/101')
		expect(link?.getAttribute('target')).toBe('_blank')

		// Partial coverage notice is visible without disclosing excluded counts or names
		const partialNotice = root.querySelector('[role="status"]')
		expect(partialNotice?.textContent).toContain('Partial folder listing. Some entries were excluded by policy or limit.')
		expect(root.textContent).not.toContain('excluded count')
		expect(root.textContent).not.toContain('hidden file')

		app.unmount()
	})

	it('FolderResultsCard renders empty folder state gracefully', () => {
		const mockHost = {
			chat: { isBusy: false },
			config: {},
		} as unknown as NextcloudAgent

		const result: FolderOverviewResults = {
			kind: 'nextcloud-folder-overview',
			directory: '/EmptyFolder',
			entries: [],
			partial: false,
		}

		const root = document.createElement('div')
		const app = createApp(FolderResultsCard, { result, host: mockHost })
		app.mount(root)

		expect(root.textContent).toContain('Folder is empty.')
		expect(root.querySelector('[role="status"]')).toBeNull()

		app.unmount()
	})

	it('SharesResultsCard renders coverage warning, recipient info, permissions, inherited state, and expiration', () => {
		const mockHost = {
			chat: { isBusy: false },
			config: {},
		} as unknown as NextcloudAgent

		const shares: ShareEntry[] = [
			{ id: 10, shareType: 0, shareWith: 'bob', shareWithDisplayName: 'Bob Pilot', permissions: 1, path: '/Doc.md', inherited: false },
			{ id: 11, shareType: 1, shareWith: 'qa-group', shareWithDisplayName: 'QA Group', permissions: 31, path: '/Doc.md', inherited: true },
			{ id: 12, shareType: 3, shareWith: 'public-link', shareWithDisplayName: 'Public link', permissions: 1, path: '/Doc.md', expiration: '2026-11-30', inherited: false },
		]

		const result: ShareOverviewResults = {
			kind: 'nextcloud-share-overview',
			resourceId: 'nextcloud:files:101',
			etag: 'etag-s',
			shares,
			complete: false,
			coverage: 'Current-user user/group/public-link shares only.',
		}

		const root = document.createElement('div')
		const app = createApp(SharesResultsCard, { result, host: mockHost })
		app.mount(root)

		// Header and target link
		expect(root.textContent).toContain('Shares and permissions')
		const targetLink = root.querySelector<HTMLAnchorElement>('.fdn-shares-title a')
		expect(targetLink).not.toBeNull()
		expect(targetLink?.getAttribute('href')).toContain('/f/101')

		// Coverage warning is prominently rendered
		const coverage = root.querySelector('.fdn-shares-coverage')
		expect(coverage?.textContent).toContain("Covers supported shares initiated by the current user. May include expired shares; this is not a complete access inventory.")

		// User share
		expect(root.textContent).toContain('Bob Pilot')
		expect(root.textContent).toContain('User')
		expect(root.textContent).toContain('Read only')

		// Group share (inherited, read & write)
		expect(root.textContent).toContain('QA Group')
		expect(root.textContent).toContain('Group')
		expect(root.textContent).toContain('Read, Edit, Create, Delete, Share')
		expect(root.textContent).toContain('(Inherited)')

		// Public link share with expiration
		expect(root.textContent).toContain('Public link')
		expect(root.textContent).toContain('Expires: 2026-11-30')

		// Secret exclusion: no passwords, tokens, or URL paths exposed
		expect(root.textContent).not.toContain('secret-token')
		expect(root.textContent).not.toContain('password')

		app.unmount()
	})

	it('SharesResultsCard handles empty shares without claiming complete access', () => {
		const mockHost = {
			chat: { isBusy: false },
			config: {},
		} as unknown as NextcloudAgent

		const result: ShareOverviewResults = {
			kind: 'nextcloud-share-overview',
			resourceId: 'nextcloud:files:101',
			etag: 'etag-s',
			shares: [],
			complete: false,
			coverage: 'Current-user user/group/public-link shares only.',
		}

		const root = document.createElement('div')
		const app = createApp(SharesResultsCard, { result, host: mockHost })
		app.mount(root)

		expect(root.textContent).toContain('No supported shares were returned within this coverage.')
		expect(root.textContent).toContain('Covers supported shares initiated by the current user.')
		expect(root.textContent).not.toContain('Nobody has access')

		app.unmount()
	})

	it('renders FolderResultsCard and SharesResultsCard through real SDK AgentChat turn lifecycle', async () => {
		vi.stubGlobal('ResizeObserver', class {

			observe() {} disconnect() {}

		})

		let round = 0
		const { host, files } = fixture(() => {
			round++
			if (round === 1) {
				return {
					toolCalls: [{
						id: 'organize-call',
						name: 'nc_files_organize_read',
						arguments: { resource_id: 'nextcloud:files:202' },
					}],
				}
			}
			if (round === 2) {
				return { text: 'Here are the folder contents.' }
			}
			if (round === 3) {
				return {
					toolCalls: [{
						id: 'shares-call',
						name: 'nc_files_shares_read',
						arguments: { resource_id: 'nextcloud:files:101' },
					}],
				}
			}
			return { text: 'Here are the file shares.' }
		})

		await host.signIn('alice', 'test')
		await host.selection.select(202)
		host.offerSelection(selectedFolder)

		const root = document.createElement('div')
		document.body.append(root)
		const app = createApp(AgentChat, { host: markRaw(host) })
		app.mount(root)

		try {
			// 1. Organize Read Turn
			await host.send('List the contents of Projects')
			await vi.waitFor(() => expect(host.chat.isBusy).toBe(false))

			// Verify FolderResultsCard rendered inside the turn
			await vi.waitFor(() => expect(root.querySelector('.fdn-folder-results')).not.toBeNull())
			expect(root.querySelector('.fdn-folder-title')?.textContent).toContain('/Projects')
			expect(root.querySelector('.fdn-folder-results')?.textContent).toContain('Contract.md')
			expect(root.querySelector('.fdn-folder-results')?.textContent).toContain('Archive')
			expect(root.querySelector('.fdn-folder-results [role="status"]')?.textContent).toContain('Partial folder listing.')
			expect(files.organizeRead).toHaveBeenCalledTimes(1)

			// 2. Shares Read Turn
			await host.send('Inspect shares on Contract.md')
			await vi.waitFor(() => expect(host.chat.isBusy).toBe(false))

			// Verify SharesResultsCard rendered inside the turn
			await vi.waitFor(() => expect(root.querySelector('.fdn-shares-results')).not.toBeNull())
			expect(root.querySelector('.fdn-shares-coverage')?.textContent).toContain('Covers supported shares initiated by the current user.')
			expect(root.querySelector('.fdn-shares-results')?.textContent).toContain('Bob Pilot')
			expect(root.querySelector('.fdn-shares-results')?.textContent).toContain('Development Team')
			expect(root.querySelector('.fdn-shares-results')?.textContent).toContain('Public link')
			expect(files.sharesRead).toHaveBeenCalledTimes(1)

			// Read operations do not mutate native selection or call commit/write
			expect(host.history.items).toHaveLength(0)
		} finally {
			app.unmount()
			root.remove()
			await host.dispose()
		}
	})

	it('Stop while a read tool is pending suppresses late result and prevents UI disclosure', async () => {
		vi.stubGlobal('ResizeObserver', class {

			observe() {} disconnect() {}

		})

		let pendingResolve: () => void = () => {}
		const { host, files } = fixture(() => ({
			toolCalls: [{
				id: 'slow-read-call',
				name: 'nc_files_organize_read',
				arguments: { resource_id: 'nextcloud:files:202' },
			}],
		}), {
			organizeRead: vi.fn(() => new Promise((resolve) => {
				pendingResolve = () => resolve({
					directory: '/Projects',
					resourceId: 'nextcloud:files:202',
					etag: 'folder-rev',
					entries: [{ resourceId: 'nextcloud:files:101', href: '/f/101', filename: 'Confidential.md', etag: 'sec', folder: false }],
					partial: false,
				})
			})),
		})

		await host.signIn('alice', 'test')
		host.offerSelection(await host.selection.select(202))
		const root = document.createElement('div')
		document.body.append(root)
		const app = createApp(AgentChat, { host: markRaw(host) })
		app.mount(root)

		try {
			const input = root.querySelector('textarea')!
			input.value = 'Slow folder read'
			input.dispatchEvent(new Event('input', { bubbles: true }))
			await nextTick()
			root.querySelector<HTMLButtonElement>('.fdn-send')!.click()

			await vi.waitFor(() => expect(files.organizeRead).toHaveBeenCalledOnce())

			// User stops during execution
			host.stop()
			await vi.waitFor(() => expect(host.chat.isBusy).toBe(false))

			// Complete the delayed read late
			pendingResolve()
			await nextTick()

			// Confidential.md must NOT be disclosed in the DOM
			expect(root.querySelector('.fdn-folder-results')).toBeNull()
			expect(root.textContent).not.toContain('Confidential.md')
		} finally {
			app.unmount()
			root.remove()
			await host.dispose()
		}
	})
})
