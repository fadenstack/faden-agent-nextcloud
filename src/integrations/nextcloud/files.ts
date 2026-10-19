import axios from '@nextcloud/axios'
import { generateUrl, generateRemoteUrl } from '@nextcloud/router'
import type { FileApi, Identity, FileContext, Listing, Proposal, ReadResult, WriteArguments, WriteMark, WriteResult, SearchTransport, SearchPlan, FileMetadata, SearchCapabilities, NativeContentResults, DavCreatePlan } from './types.ts'

export class NextcloudFiles implements FileApi {

	constructor(readonly initial: Identity) {}
	readonly searchTransport: SearchTransport = {
		plan: (filters, signal) => this.post<SearchPlan>('/search/plan', { filters }, signal),
		resolve: (resourceIds, scope, signal, content = false) => this.post<FileMetadata[]>('/search/resolve', { resourceIds, scope, content }, signal),
		capabilities: signal => this.post<SearchCapabilities>('/search/capabilities', {}, signal),
		content: (query, limit, page, signal) => this.post<NativeContentResults>('/search/content', { query, limit, page }, signal),
		dav: async (xml, signal) => {
			await this.assertIdentity()
			const response = await axios.request<string>({
				url: generateRemoteUrl('dav/'),
				method: 'SEARCH',
				data: xml,
				headers: { 'Content-Type': 'text/xml; charset=utf-8' },
				responseType: 'text',
				signal,
				timeout: 15000,
			})
			if (response.status !== 207) throw new Error('Native WebDAV SEARCH is unavailable on this Nextcloud server.')
			return response.data
		},
	}

	async identity(): Promise<Identity> {
		return (await axios.get<Identity>(generateUrl('/apps/fadenstack_agent/config'), { timeout: 10000 })).data
	}

	async assertIdentity(): Promise<void> {
		const now = await this.identity()
		if (now.userId !== this.initial.userId || now.binding !== this.initial.binding
			|| now.serverUrl !== this.initial.serverUrl || now.agent !== this.initial.agent || !now.enabled) {
			throw new Error('The Nextcloud session or agent configuration changed. Reload and sign in again.')
		}
	}

	async post<T>(path: string, body: unknown, signal?: AbortSignal): Promise<T> {
		try {
			return (await axios.post<T>(generateUrl(`/apps/fadenstack_agent${path}`), body, {
				headers: { 'X-Fadenstack-Session': this.initial.binding }, signal, timeout: 20000,
			})).data
		} catch (error) { throw nextcloudError(error) }
	}

	select(fileId: number, signal?: AbortSignal): Promise<FileContext> { return this.post('/context', { fileId }, signal) }
	read(contextId: string, signal?: AbortSignal): Promise<ReadResult> { return this.post('/context/read', { contextId }, signal) }
	list(contextId: string, signal?: AbortSignal): Promise<Listing> { return this.post('/context/list', { contextId }, signal) }
	propose(contextId: string, args: WriteArguments, signal?: AbortSignal): Promise<Proposal> {
		return this.post('/proposals', { contextId, ...args }, signal)
	}

	async approve(id: string, signal?: AbortSignal): Promise<void> { await this.post(`/proposals/${encodeURIComponent(id)}/approve`, {}, signal) }
	async commit(id: string, signal?: AbortSignal, mark?: WriteMark): Promise<WriteResult> {
		// The marked text and the signed record go with the commit: FileService writes the marked text only when it is
		// the approved one with its mark, and keeps the record as the file's metadata. A creation's plan carries the
		// text it accepted, which the PUT below writes.
		const marks = mark ? { content: mark.content, ...(mark.provenance ? { provenance: mark.provenance } : {}) } : {}
		const result = await this.post<WriteResult | DavCreatePlan>(`/proposals/${encodeURIComponent(id)}/commit`, { createProtocol: 1, ...marks }, signal)
		if (!('kind' in result)) return result
		const prefix = `/files/${encodeURIComponent(this.initial.userId)}/`
		if (result.kind !== 'dav-create' || !result.dav_path.startsWith(prefix) || /[?#\r\n]/.test(result.dav_path)) throw new Error('Invalid approved Nextcloud creation plan.')
		await this.assertIdentity()
		const [parent] = await this.searchTransport.resolve([result.parent_resource_id], 'all', signal ?? AbortSignal.timeout(20000))
		if (!parent?.folder || parent.path !== result.parent_path || parent.etag !== result.parent_etag || !(parent.permissions & 4)) throw new Error('The approved destination changed. No write was performed.')
		const put = await axios.put(generateRemoteUrl('dav') + result.dav_path, result.content, {
			headers: { 'Content-Type': 'text/plain; charset=utf-8', 'If-None-Match': '*', 'X-OC-CTime': String(Math.floor(Date.now() / 1000)) }, signal, timeout: 20000,
		}).catch(error => { throw nextcloudError(error) })
		if (put.status !== 201) throw new Error('Nextcloud did not confirm a new file creation. No success is claimed.')
		await this.assertIdentity()
		return this.post('/creations/finish', { receiptId: result.receiptId }, signal)
	}

	async pdf(contextId: string, signal?: AbortSignal): Promise<Uint8Array> {
		const response = await axios.post<ArrayBuffer>(generateUrl('/apps/fadenstack_agent/context/content'), { contextId }, {
			headers: { 'X-Fadenstack-Session': this.initial.binding }, responseType: 'arraybuffer', signal, timeout: 20000,
		})
		return new Uint8Array(response.data)
	}

}

/**
 * A request Nextcloud answered with an error, in Nextcloud's own words ("The file is busy. Try again.", where axios
 * says only "Request failed with status code 423"): the model and the user read why, and the name says what failed.
 * A write refused after its record was signed is reported to the server as not written with that name as its
 * `failure` code, so an auditor reads `NextcloudLocked` or `NextcloudConflict` instead of the HTTP library's class.
 */
export class NextcloudRequestError extends Error {

	constructor(readonly status: number, message: string, options?: { cause?: unknown }) {
		super(message, options)
		this.name = requestErrorName(status)
	}

}

/** What failed, by the status Nextcloud answered with: a short name, nothing of the request or the file. */
export function requestErrorName(status: number): string {
	switch (status) {
	case 403: return 'NextcloudForbidden'
	case 404: return 'NextcloudNotFound'
	case 409: return 'NextcloudConflict'
	case 412: return 'NextcloudPreconditionFailed'
	case 423: return 'NextcloudLocked'
	case 429: return 'NextcloudTooManyRequests'
	default: return status >= 500 ? 'NextcloudServerError' : 'NextcloudRequestFailed'
	}
}

/** An error Nextcloud answered as a NextcloudRequestError; anything else (no answer, a timeout, a stop) as it is. */
export function nextcloudError(error: unknown): unknown {
	const status = (error as { response?: { status?: unknown } })?.response?.status
	return typeof status === 'number' ? new NextcloudRequestError(status, errorMessage(error), { cause: error }) : error
}

export function errorMessage(error: unknown): string {
	const response = (error as { response?: { data?: { message?: unknown } } })?.response
	return typeof response?.data?.message === 'string'
		? response.data.message
		: error instanceof Error ? error.message : 'The operation failed.'
}
