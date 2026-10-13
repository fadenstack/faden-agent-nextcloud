import type { FileApi, FileMetadata, FileResource, SearchFilters, SearchResults } from './types.ts'

export function resourceId(value: unknown): number {
	if (typeof value !== 'string' || !/^nextcloud:files:[1-9]\d{0,14}$/.test(value)) throw new Error('Use a Nextcloud file resource ID, not a path or URL.')
	const id = Number(value.slice('nextcloud:files:'.length))
	if (!Number.isSafeInteger(id)) throw new Error('Invalid file resource ID.')
	return id
}
export function fileResource(file: FileMetadata): FileResource {
	return {
		resource_id: `nextcloud:files:${file.id}`,
		name: file.name,
		path: file.path.substring(0, file.path.lastIndexOf('/') + 1) || '/',
		mime_type: file.mime,
		size: file.size,
		modified_at: file.modified ? new Date(file.modified * 1000).toISOString() : null,
		etag: file.etag,
		web_url: file.url,
		is_directory: file.folder,
		capabilities: ['read', 'open'],
	}
}
export function davResourceIds(xml: string): string[] {
	if (xml.length > 1024 * 1024 || /<!DOCTYPE|<!ENTITY/i.test(xml)) throw new Error('Invalid or oversized DAV search response.')
	const doc = new DOMParser().parseFromString(xml, 'application/xml')
	if (doc.querySelector('parsererror') || doc.documentElement.namespaceURI !== 'DAV:' || doc.documentElement.localName !== 'multistatus') throw new Error('Invalid DAV search response.')
	const ids: string[] = []
	for (const response of doc.getElementsByTagNameNS('DAV:', 'response')) {
		for (const stat of response.getElementsByTagNameNS('DAV:', 'propstat')) {
			if (!/\s200\s/.test(stat.getElementsByTagNameNS('DAV:', 'status')[0]?.textContent || '')) continue
			const id = stat.getElementsByTagNameNS('http://owncloud.org/ns', 'fileid')[0]?.textContent
			if (id && /^[1-9]\d{0,14}$/.test(id)) ids.push(`nextcloud:files:${id}`)
		}
	}
	if (ids.length > 51) throw new Error('DAV backend exceeded the bounded query limit.')
	return [...new Set(ids)]
}

/** One shared service for discovery, authorized retrieval and document search. */
export class NextcloudSearchService {

	readonly issued = new Map<string, FileMetadata>()
	constructor(readonly files: FileApi) {}
	async search(filters: SearchFilters, signal: AbortSignal): Promise<SearchResults> {
		const started = performance.now()
		await this.files.assertIdentity()
		const transport = this.files.searchTransport
		if (!transport) throw new Error('Native search transport is unavailable.')
		const deadline = AbortSignal.any([signal, AbortSignal.timeout(20000)])
		const plan = await transport.plan(filters, deadline)
		const found: FileMetadata[] = []
		let partial = false
		for (const xml of plan.queries) {
			deadline.throwIfAborted()
			const ids = davResourceIds(await transport.dav(xml, deadline))
			const authorized = await transport.resolve(ids, plan.filters.scope, deadline)
			authorized.sort((a, b) => {
				const direction = plan.filters.sort_direction === 'asc' ? 1 : -1
				const by = plan.filters.sort_by
				const primary = by === 'name' ? (a.name < b.name ? -1 : a.name > b.name ? 1 : 0) : by === 'size' ? a.size - b.size : (a.modified ?? 0) - (b.modified ?? 0)
				return primary * (by === 'relevance' ? -1 : direction) || (a.path < b.path ? -1 : a.path > b.path ? 1 : a.id - b.id)
			})
			found.push(...authorized.filter(f => !found.some(other => other.id === f.id)))
			if (ids.length >= plan.filters.limit + 1) partial = true
			if (found.length > plan.filters.limit) { partial = true; break }
		}
		const rows = found.slice(0, plan.filters.limit)
		for (const file of rows) this.remember(file)
		return {
			kind: 'nextcloud-search-results',
			search_mode: 'filenames',
			provider: plan.backend,
			ordering: plan.filters.sort_by === 'relevance' && plan.filters.query ? 'exact-name, prefix, substring; modified descending within each tier' : `${plan.filters.sort_by === 'relevance' ? 'modified' : plan.filters.sort_by} ${plan.filters.sort_by === 'relevance' ? 'desc' : plan.filters.sort_direction}`,
			results: rows.map(fileResource),
			partial,
			duration_ms: Math.round(performance.now() - started),
		}
	}

	remember(file: FileMetadata) {
		this.issued.set(`nextcloud:files:${file.id}`, file)
		while (this.issued.size > 150) this.issued.delete(this.issued.keys().next().value!)
	}

	clear() { this.issued.clear() }

}
