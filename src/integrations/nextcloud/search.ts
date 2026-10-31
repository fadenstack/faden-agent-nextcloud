import type { FileApi, FileMetadata, FileResource, SearchFilters, SearchResults, FileContext, DocumentSearchResults } from './types.ts'
import { findPassages, type DocumentText } from './passages.ts'

/** Bound metadata/excerpts separately from the existing document text limit. */
export function boundedResults<T>(rows: T[]): { rows: T[]; truncated: boolean } {
	const kept: T[] = []
	let bytes = 0
	for (const row of rows) {
		const size = new TextEncoder().encode(JSON.stringify(row)).byteLength
		if (bytes + size > 60 * 1024) return { rows: kept, truncated: true }
		kept.push(row)
		bytes += size
	}
	return { rows: kept, truncated: false }
}

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
	readonly #nativeReferences = new Set<string>()
	readonly cache = new Map<string, { context: FileContext; document: DocumentText; expires: number }>()
	constructor(readonly files: FileApi, readonly extract: (context: FileContext, signal: AbortSignal) => Promise<DocumentText> = async (context, signal) => {
		if (context.file.mime !== 'application/pdf') return files.read(context.id, signal)
		const { extractPdf } = await import('./pdf.ts')
		return extractPdf(await files.pdf(context.id, signal), signal)
	}) {}

	async authorize() {
		try { await this.files.assertIdentity() } catch (error) { this.clear(); throw error }
	}

	async search(filters: SearchFilters, signal: AbortSignal): Promise<SearchResults> {
		const started = performance.now()
		await this.authorize()
		const transport = this.files.searchTransport
		if (!transport) throw new Error('Native search transport is unavailable.')
		const deadline = AbortSignal.any([signal, AbortSignal.timeout(20000)])
		const plan = await transport.plan(filters, deadline)
		const found: FileMetadata[] = []
		let partial = false
		for (const xml of plan.queries) {
			deadline.throwIfAborted()
			const ids = davResourceIds(await transport.dav(xml, deadline))
			const authorized = await transport.resolve(plan.next_cursor ? ids.slice(0, plan.filters.limit) : ids, plan.filters.scope, deadline)
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
		const bounded = boundedResults(found.slice(0, plan.filters.limit).map(fileResource))
		const rows = found.slice(0, bounded.rows.length)
		for (const file of rows) this.remember(file)
		return {
			kind: 'nextcloud-search-results',
			search_mode: 'filenames',
			provider: plan.backend,
			ordering: plan.filters.sort_by === 'relevance' && plan.filters.query ? 'exact-name, prefix, substring; modified descending within each tier' : `${plan.filters.sort_by === 'relevance' ? 'modified' : plan.filters.sort_by} ${plan.filters.sort_by === 'relevance' ? 'desc' : plan.filters.sort_direction}`,
			results: bounded.rows,
			partial: partial || bounded.truncated,
			duration_ms: Math.round(performance.now() - started),
			next_cursor: partial && !bounded.truncated ? plan.next_cursor : null,
		}
	}

	remember(file: FileMetadata) {
		this.rememberReference(`nextcloud:files:${file.id}`)
		this.issued.set(`nextcloud:files:${file.id}`, file)
	}

	/** Introduced only by native user selection or scope-validated discovery; still re-resolve before use. */
	rememberReference(reference: string) {
		resourceId(reference)
		this.#nativeReferences.add(reference)
		while (this.#nativeReferences.size > 150) {
			const first = this.#nativeReferences.values().next().value!
			this.#nativeReferences.delete(first)
			this.issued.delete(first)
			this.cache.delete(first)
		}
	}

	async metadata(id: unknown, signal: AbortSignal, selected?: FileContext, content = false): Promise<FileMetadata> {
		const number = resourceId(id)
		if (selected?.file.id === number) this.remember(selected.file)
		if (!this.issued.has(String(id)) && !this.#nativeReferences.has(String(id))) throw new Error('Use a resource returned by search or explicitly attached by the user first.')
		await this.authorize()
		signal.throwIfAborted()
		const found = this.files.searchTransport
			? (await this.files.searchTransport.resolve([String(id)], 'all', signal, content))[0]
			: (await this.files.select(number, signal)).file
		signal.throwIfAborted()
		if (found && found.id !== number) throw new Error('The native resolver returned a different resource.')
		if (!found) { this.cache.delete(String(id)); this.issued.delete(String(id)); this.#nativeReferences.delete(String(id)); throw new Error('This file was deleted, moved out of your access, or permission was revoked.') }
		const old = this.issued.get(String(id))
		if (!old || found.etag !== old.etag || found.path !== old.path) this.cache.delete(String(id))
		this.remember(found)
		return found
	}

	async load(id: string, signal: AbortSignal, selected?: FileContext) {
		const started = performance.now()
		const metadata = await this.metadata(id, signal, selected)
		if (metadata.folder) throw new Error('Search a document, not a folder. Use file discovery or folder listing.')
		const current = await this.metadata(id, signal, selected, true)
		if (selected && (selected.file.etag !== current.etag || selected.file.path !== current.path)) throw new Error('The included file changed. Reattach it before sending.')
		const cached = this.cache.get(id)
		if (this.files.searchTransport && cached && cached.expires > Date.now() && cached.context.expires * 1000 > Date.now()
			&& cached.context.file.etag === current.etag && cached.context.file.path === current.path) {
			return { ...cached, cache_hit: true, retrieval_ms: Math.round(performance.now() - started) }
		}
		const context = selected ?? await this.files.select(resourceId(id), signal)
		const document = await this.extract(context, signal)
		signal.throwIfAborted()
		const after = await this.metadata(id, signal, context, true)
		if (context.file.etag !== after.etag || context.file.path !== after.path) throw new Error('The document changed during extraction. Search it again.')
		const entry = { context, document, expires: Date.now() + 300000 }
		this.cache.delete(id)
		this.cache.set(id, entry)
		while (this.cache.size > 4) this.cache.delete(this.cache.keys().next().value!)
		return { ...entry, cache_hit: false, retrieval_ms: Math.round(performance.now() - started) }
	}

	async read(id: string, signal: AbortSignal, selected?: FileContext) {
		const result = await this.load(id, signal, selected)
		const document = { ...result.document }
		delete document.pages
		return { file: result.context.file, resource: fileResource(result.context.file), ...document, cache_hit: result.cache_hit, retrieval_ms: result.retrieval_ms }
	}

	async inFile(id: string, query: unknown, mode: unknown, maximum: unknown, signal: AbortSignal, selected?: FileContext): Promise<DocumentSearchResults> {
		findPassages({ text: '', partial: false }, query, mode, maximum)
		const started = performance.now()
		const result = await this.load(id, signal, selected)
		return {
			kind: 'nextcloud-document-search',
			search_mode: 'document',
			provider: result.context.file.mime === 'application/pdf' ? 'pdfjs' : 'nextcloud-text',
			file: fileResource(result.context.file),
			...findPassages(result.document, query, mode, maximum),
			cache_hit: result.cache_hit,
			retrieval_ms: result.retrieval_ms,
			duration_ms: Math.round(performance.now() - started),
		}
	}

	async content(query: unknown, limit: unknown, page: unknown, signal: AbortSignal): Promise<SearchResults> {
		if (typeof query !== 'string' || !query.trim() || query.length > 200 || /[\x00-\x1f\x7f]/.test(query)
			|| typeof limit !== 'number' || !Number.isInteger(limit) || limit < 1 || limit > 50 || typeof page !== 'number' || !Number.isInteger(page) || page < 1 || page > 100) throw new Error('Invalid native content-search query, limit or page.')
		await this.authorize()
		const started = performance.now()
		if (!this.files.searchTransport) throw new Error('Native content search is unavailable.')
		const data = await this.files.searchTransport.content(query, limit, page, AbortSignal.any([signal, AbortSignal.timeout(20000)]))
		const bounded = boundedResults(data.results.map(({ file, excerpt }) => ({ ...fileResource(file), excerpt })))
		for (const { file } of data.results.slice(0, bounded.rows.length)) this.remember(file)
		return {
			kind: 'nextcloud-search-results',
			search_mode: data.provider === 'nextcloud-bounded-text' ? 'bounded-content' : 'indexed-content',
			provider: data.provider ?? 'unavailable',
			available: data.available,
			explanation: data.explanation,
			ordering: data.provider === 'nextcloud-bounded-text' ? 'bounded native candidate order; literal text matches' : 'native provider relevance',
			results: bounded.rows,
			partial: (data.partial ?? false) || bounded.truncated,
			next_page: bounded.truncated ? null : data.next_page,
			duration_ms: Math.round(performance.now() - started),
		}
	}

	clear() { this.issued.clear(); this.#nativeReferences.clear(); this.cache.clear() }

}
