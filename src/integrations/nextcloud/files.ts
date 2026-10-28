import axios from '@nextcloud/axios'
import { getRequestToken } from '@nextcloud/auth'
import { generateUrl, generateRemoteUrl } from '@nextcloud/router'
import type { FileApi, Identity, FileContext, Listing, Proposal, ReadResult, WriteArguments, WriteResult, SearchTransport, SearchPlan, FileMetadata, SearchCapabilities, NativeContentResults, DavCreatePlan, HistoryApi } from './types.ts'
import type { ResourcePurpose, NativeResource } from '../../platform/scope.ts'

const HISTORY_CONFLICTS: Record<string, string> = {
	inspection_inconclusive: 'The file content does not establish whether this attempt completed. Its outcome remains unresolved. Review the file and action history before making further changes.',
	revalidation_content_changed: 'The file content does not match its recorded state. Its revision was not verified. Review the file and action history before making further changes.',
	revalidation_not_needed: 'The recorded file revision is already current. Review the available Undo or Redo action.',
}

export class NextcloudFiles implements FileApi {

	constructor(readonly initial: Identity) {}
	#cloudIdentity: ((signal?: AbortSignal) => Promise<string>) | undefined
	setCloudIdentity(provider?: (signal?: AbortSignal) => Promise<string>): void { this.#cloudIdentity = provider }
	readonly history: HistoryApi = {
		availability: signal => this.authenticatedPost('/history/availability', {}, signal),
		list: signal => this.authenticatedPost('/history/list', {}, signal),
		prepare: (actionId, direction, signal) => this.authenticatedPost('/history/prepare', { actionId, direction }, signal),
		approve: async (preview, signal) => { await this.authenticatedPost(this.historyPath(preview.id, preview.digest, 'approve'), { digest: preview.digest }, signal) },
		execute: (preview, signal) => this.authenticatedPost(this.historyPath(preview.id, preview.digest, 'execute'), { digest: preview.digest }, signal),
		cancel: async (preview, signal) => { await this.authenticatedPost(this.historyPath(preview.id, preview.digest, 'cancel'), { digest: preview.digest }, signal) },
	}

	private historyPath(id: string, digest: string, operation: 'approve' | 'execute' | 'cancel'): string {
		if (!/^[a-f0-9]{48}$/.test(id) || !/^[a-f0-9]{64}$/.test(digest)) throw new Error('Invalid history preview.')
		return `/history/${id}/${operation}`
	}

	private async authenticatedPost<T>(path: string, body: unknown, signal?: AbortSignal): Promise<T> {
		signal?.throwIfAborted()
		await this.assertIdentity(signal)
		if (!this.#cloudIdentity) throw new Error('Sign in to Fadenstack again.')
		const token = await this.#cloudIdentity(signal)
		signal?.throwIfAborted()
		const url = generateUrl(`/apps/fadenstack_agent${path}`)
		if (new URL(url, location.href).origin !== location.origin) throw new Error('The Nextcloud request origin changed.')
		const response = await fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			redirect: 'error',
			cache: 'no-store',
			headers: { 'Content-Type': 'application/json', requesttoken: getRequestToken() ?? '', 'X-Fadenstack-Session': this.initial.binding, 'X-Fadenstack-Agent-Token': token },
			body: JSON.stringify(body),
			signal: signal ? AbortSignal.any([signal, AbortSignal.timeout(20000)]) : AbortSignal.timeout(20000),
		})
		const data = await response.json() as T & { message?: unknown, code?: unknown }
		if (!response.ok) {
			const message = response.status === 409 && typeof data.code === 'string' && typeof HISTORY_CONFLICTS[data.code] === 'string'
				? HISTORY_CONFLICTS[data.code]!
				: typeof data.message === 'string' ? data.message : 'The Nextcloud operation could not be confirmed.'
			throw Object.assign(new Error(message), { response: { status: response.status, data: { ...data, message } } })
		}
		return data
	}

	readonly searchTransport: SearchTransport = {
		plan: (filters, signal) => this.post<SearchPlan>('/search/plan', { filters }, signal),
		resolve: (resourceIds, scope, signal, content = false) => this.post<FileMetadata[]>('/search/resolve', { resourceIds, scope, content }, signal),
		capabilities: signal => this.post<SearchCapabilities>('/search/capabilities', {}, signal),
		content: (query, limit, page, signal) => this.post<NativeContentResults>('/search/content', { query, limit, page }, signal),
		dav: async (xml, signal) => {
			await this.assertIdentity(signal)
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

	async identity(signal?: AbortSignal): Promise<Identity> {
		return (await axios.get<Identity>(generateUrl('/apps/fadenstack_agent/config'), { timeout: 10000, signal })).data
	}

	async assertIdentity(signal?: AbortSignal): Promise<void> {
		const now = await this.identity(signal)
		if (now.userId !== this.initial.userId || now.binding !== this.initial.binding
			|| now.historyEnabled !== this.initial.historyEnabled
			|| now.scopePolicy?.version !== this.initial.scopePolicy?.version
			|| now.serverUrl !== this.initial.serverUrl || now.agent !== this.initial.agent || !now.enabled) {
			throw new Error('The Nextcloud session or agent configuration changed. Reload and sign in again.')
		}
	}

	async post<T>(path: string, body: unknown, signal?: AbortSignal): Promise<T> {
		if (this.initial.historyEnabled && (/^\/proposals(?:\/|$)/.test(path) || path === '/creations/finish')) return this.authenticatedPost(path, body, signal)
		return (await axios.post<T>(generateUrl(`/apps/fadenstack_agent${path}`), body, {
			headers: { 'X-Fadenstack-Session': this.initial.binding }, signal, timeout: 20000,
		})).data
	}

	select(fileId: number, signal?: AbortSignal): Promise<FileContext> { return this.post('/context', { fileId }, signal) }
	scopeResource(resourceId: string, purpose: ResourcePurpose, signal: AbortSignal): Promise<NativeResource> { return this.post('/scope/resource', { resourceId, purpose }, signal) }
	read(contextId: string, signal?: AbortSignal): Promise<ReadResult> { return this.post('/context/read', { contextId }, signal) }
	list(contextId: string, signal?: AbortSignal): Promise<Listing> { return this.post('/context/list', { contextId }, signal) }
	propose(contextId: string, args: WriteArguments, signal?: AbortSignal): Promise<Proposal> {
		return this.post('/proposals', { contextId, ...args }, signal)
	}

	async approve(id: string, signal?: AbortSignal): Promise<void> { await this.post(`/proposals/${encodeURIComponent(id)}/approve`, {}, signal) }
	async commit(id: string, signal?: AbortSignal): Promise<WriteResult> {
		const result = await this.post<WriteResult | DavCreatePlan>(`/proposals/${encodeURIComponent(id)}/commit`, { createProtocol: 1 }, signal)
		if (!('kind' in result)) return result
		const prefix = `/files/${encodeURIComponent(this.initial.userId)}/`
		if (result.kind !== 'dav-create' || !result.dav_path.startsWith(prefix) || /[?#\r\n]/.test(result.dav_path)) throw new Error('Invalid approved Nextcloud creation plan.')
		await this.assertIdentity(signal)
		const [parent] = await this.searchTransport.resolve([result.parent_resource_id], 'all', signal ?? AbortSignal.timeout(20000))
		if (!parent?.folder || parent.path !== result.parent_path || parent.etag !== result.parent_etag || !(parent.permissions & 4)) throw new Error('The approved destination changed. No write was performed.')
		const put = await axios.put(generateRemoteUrl('dav') + result.dav_path, result.content, {
			headers: { 'Content-Type': 'text/plain; charset=utf-8', 'If-None-Match': '*', 'X-OC-CTime': String(Math.floor(Date.now() / 1000)) }, signal, timeout: 20000,
		})
		if (put.status !== 201) throw new Error('Nextcloud did not confirm a new file creation. No success is claimed.')
		await this.assertIdentity(signal)
		return this.post('/creations/finish', { receiptId: result.receiptId }, signal)
	}

	async pdf(contextId: string, signal?: AbortSignal): Promise<Uint8Array> {
		const response = await axios.post<ArrayBuffer>(generateUrl('/apps/fadenstack_agent/context/content'), { contextId }, {
			headers: { 'X-Fadenstack-Session': this.initial.binding }, responseType: 'arraybuffer', signal, timeout: 20000,
		})
		return new Uint8Array(response.data)
	}

}

export function errorMessage(error: unknown): string {
	const response = (error as { response?: { data?: { message?: unknown } } })?.response
	return typeof response?.data?.message === 'string'
		? response.data.message
		: error instanceof Error ? error.message : 'The operation failed.'
}
