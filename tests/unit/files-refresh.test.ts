import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { FileMetadata } from '../../src/integrations/nextcloud/types.ts'
import { refreshFiles } from '../../src/workspace/filesRefresh.ts'

const native = vi.hoisted(() => ({ emit: vi.fn(), stat: vi.fn(), node: { fileid: 12, basename: 'note.md' } }))
vi.mock('@nextcloud/event-bus', () => ({ emit: native.emit }))
vi.mock('@nextcloud/files/dav', () => ({
	getClient: () => ({ stat: native.stat }), defaultRootPath: '/files/test', getDefaultPropfind: () => 'native-properties',
	resultToNode: () => native.node,
}))
const file = { id: 12, name: 'note.md', path: '/note.md', etag: 'verified-revision' } as FileMetadata

beforeEach(() => {
	vi.clearAllMocks()
	Object.assign(window, { OC: { config: { version: '35.0.2' } } })
	native.stat.mockResolvedValue({ data: { etag: 'verified-revision' } })
})

describe('Native Files refresh after history', () => {
	it.each(['undo', 'redo', 'reconcile'])('publishes the canonical DAV node after verified %s', async operation => {
		expect(await refreshFiles({ operation, file }, new AbortController().signal)).toBe('refreshed')
		expect(native.emit).toHaveBeenCalledExactlyOnceWith('files:node:updated', native.node)
	})
	it('refuses to announce a historical result when DAV has a newer revision', async () => {
		native.stat.mockResolvedValue({ data: { etag: 'later-human-edit' } })
		expect(await refreshFiles({ operation: 'undo', file }, new AbortController().signal)).toBe('refresh_required')
		expect(native.emit).not.toHaveBeenCalled()
	})
	it('does not infer a native effect for an unsupported history operation', async () => {
		expect(await refreshFiles({ operation: 'delete', file }, new AbortController().signal)).toBe('refresh_required')
		expect(native.emit).not.toHaveBeenCalled()
	})
})
