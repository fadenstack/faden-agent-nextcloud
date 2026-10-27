import { emit } from '@nextcloud/event-bus'
import type { WriteResult } from '../integrations/nextcloud/types.ts'
import type { ViewOutcome } from './coordinator.ts'

/** Native Files' documented stat → resultToNode → event-bus update; never a parent reload. */
export async function refreshFiles(result: WriteResult, signal: AbortSignal): Promise<ViewOutcome> {
	signal.throwIfAborted()
	const major = Number((window as unknown as { OC?: { config?: { version?: string } } }).OC?.config?.version?.split('.')[0])
	if (!Number.isInteger(major) || major < 31 || major > 35) return 'refresh_required'
	const dav = major >= 33 ? await import('@nextcloud/files/dav') : await import('@nextcloud/files-legacy/dav')
	const stat = await dav.getClient().stat(`${dav.defaultRootPath}${result.file.path}`, { details: true, data: dav.getDefaultPropfind(), signal })
	signal.throwIfAborted()
	if (!('data' in stat)) return 'refresh_required'
	if (typeof stat.data.etag !== 'string' || stat.data.etag.replace(/^"|"$/g, '') !== result.file.etag.replace(/^"|"$/g, '')) return 'refresh_required'
	const node = dav.resultToNode(stat.data)
	if (node.fileid !== result.file.id || node.basename !== result.file.name) return 'refresh_required'
	// Never use a fabricated Node from a model result. DAV supplies the canonical native object.
	if (result.operation === 'create') emit('files:node:created', node)
	// The current typed history provider only reverses/reapplies whole-text updates.
	else if (['update', 'undo', 'redo', 'reconcile'].includes(result.operation)) emit('files:node:updated', node)
	else return 'refresh_required'
	return 'refreshed'
}
