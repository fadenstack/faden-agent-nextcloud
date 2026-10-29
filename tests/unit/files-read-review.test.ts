import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp } from 'vue'
import FolderResultsCard from '../../src/components/FolderResultsCard.vue'
import SharesResultsCard from '../../src/components/SharesResultsCard.vue'
import { filesReadTools } from '../../src/integrations/nextcloud/filesExtended.ts'
import type { NextcloudAgent } from '../../src/agent/createAgent.ts'
import type { FileApi, FolderOverviewResults, ShareOverviewResults } from '../../src/integrations/nextcloud/types.ts'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => `${location.origin}/nextcloud/index.php${path}`, generateRemoteUrl: () => `${location.origin}/nextcloud/remote.php/` }))

const host = { chat: { isBusy: false }, config: { locale: 'en-US' }, addSearchContext: vi.fn(async () => {}) } as unknown as NextcloudAgent
const folder: FolderOverviewResults = { kind: 'nextcloud-folder-overview', directory: '/Projects', resourceId: 'nextcloud:files:20', etag: 'folder-rev', partial: false,
	entries: [{ resourceId: 'nextcloud:files:21', filename: '<img src=x onerror=alert(1)>.md', folder: false, href: '/f/21', etag: 'r1', modified: 1791797400, size: 22 }] }
const share: ShareOverviewResults = { kind: 'nextcloud-share-overview', resourceId: 'nextcloud:files:21', etag: 'r1', shares: [], complete: false,
	coverage: 'Only user, group and public-link shares initiated by the current user; limited to 50 records.' }
const cleanups: Array<() => void> = []
afterEach(() => { for (const cleanup of cleanups.splice(0)) cleanup() })
function mount(component: typeof FolderResultsCard | typeof SharesResultsCard, result: FolderOverviewResults | ShareOverviewResults) {
	const root = document.createElement('div')
	const app = createApp(component as typeof FolderResultsCard, { result, host })
	app.mount(root)
	cleanups.push(() => app.unmount())
	return root
}

describe('independent native read-result regressions', () => {
	it('does not assert that a filtered zero-result folder is empty', () => {
		const root = mount(FolderResultsCard, { ...folder, entries: [], partial: true })
		expect(root.textContent).not.toContain('Folder is empty.')
		expect(root.textContent).toContain('Partial folder listing')
	})
	it('uses Nextcloud base-path routing and never falls back to an arbitrary same-origin href', () => {
		const root = mount(FolderResultsCard, { ...folder, entries: [...folder.entries, { filename: 'unsafe.md', folder: false, etag: 'r2', href: '/logout?secret=hidden' }] })
		const links = [...root.querySelectorAll('a')]
		expect(links.some(link => link.getAttribute('href')?.includes('/nextcloud/index.php/f/21'))).toBe(true)
		expect(links.some(link => link.href.includes('/logout'))).toBe(false)
		expect(root.querySelector('img')).toBeNull()
	})
	it('does not crash the whole chat when a persisted date is invalid', () => {
		expect(() => mount(FolderResultsCard, { ...folder, entries: [{ ...folder.entries[0]!, modified: 1e100 }] })).not.toThrow()
	})
	it('describes destructive and resharing permissions and avoids an internal resource-id title', () => {
		const root = mount(SharesResultsCard, { ...share, shares: [{ id: 1, shareType: 0, permissions: 31, path: '/Projects/notes.md', shareWithDisplayName: '<script>alert(1)</script>', inherited: true }] })
		expect(root.textContent).toContain('Delete')
		expect(root.textContent).toContain('Share')
		expect(root.textContent).not.toContain('nextcloud:files:21')
		expect(root.querySelector('script')).toBeNull()
		expect(root.querySelector('a')?.getAttribute('href')).toContain('/nextcloud/index.php/f/21')
	})
	it('shows native coverage detail and avoids a broad no-shares claim for partial coverage', () => {
		const root = mount(SharesResultsCard, share)
		expect(root.textContent).toContain(share.coverage)
		expect(root.textContent).not.toContain('No shares found for current user')
	})
	it('rejects malformed or mismatched folder DTOs before storing UI results or introducing references', async () => {
		const recordResult = vi.fn()
		const rememberSource = vi.fn(async () => {})
		const organizeRead = vi.fn()
		const tool = filesReadTools({ files: { organizeRead } as unknown as FileApi, recordResult, rememberSource })[0]!
		for (const value of [{ ...folder, resourceId: 'nextcloud:files:99' }, { ...folder, partial: undefined }, { ...folder, entries: [{ ...folder.entries[0], resourceId: undefined }] }]) {
			organizeRead.mockResolvedValue(value)
			await expect(tool.invoke({ resource_id: 'nextcloud:files:20' }, { callId: 'bad-folder', signal: new AbortController().signal })).rejects.toThrow()
		}
		expect(recordResult).not.toHaveBeenCalled()
		expect(rememberSource).not.toHaveBeenCalled()
	})
	it('projects only reviewed folder fields before either UI or model disclosure', async () => {
		const recordResult = vi.fn()
		const files = { organizeRead: vi.fn(async () => ({ ...folder, extra: 'sensitive-unreviewed-field', entries: [{ ...folder.entries[0], href: '/logout?secret=hidden', token: 'sensitive-entry-token' }] })) } as unknown as FileApi
		const result = await filesReadTools({ files, recordResult })[0]!.invoke({ resource_id: 'nextcloud:files:20' }, { callId: 'projection', signal: new AbortController().signal })
		expect(JSON.stringify(result)).not.toContain('sensitive-')
		expect(JSON.stringify(result)).not.toContain('/logout')
		expect(JSON.stringify(result)).toContain('/nextcloud/index.php/f/21')
	})
})
