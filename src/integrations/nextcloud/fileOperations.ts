import type { FileOperationOutcome, FileOperationProposal, FileOperationsApi } from './types.ts'
import { strongEtagHeader } from './adapterGuards.ts'

export function fileOperationsApi(post: <T>(path: string, body: unknown, signal?: AbortSignal) => Promise<T>, cleanup: (id: string) => Promise<void> = async id => { await post(`/files/operations/${id}/cancel`, {}, AbortSignal.timeout(5000)) }): FileOperationsApi {
	return {
		actions: signal => post('/files/operations/actions', {}, signal),
		inspect: (id, signal) => {
			if (!/^[a-f0-9]{32}$/.test(id)) throw new Error('Invalid Files action reference.')
			return post(`/files/operations/actions/${id}/inspect`, {}, signal)
		},
		capabilities: signal => post('/files/operations/capabilities', {}, signal),
		propose: async (args, signal) => {
			const clientRequestId = 'request-' + crypto.randomUUID()
			const cancel = () => { void cleanup(clientRequestId).catch(() => {}) }
			signal.throwIfAborted()
			signal.addEventListener('abort', cancel, { once: true })
			try {
				const value = await post<FileOperationProposal>('/files/operations/propose', { ...args, clientRequestId }, signal)
				if (!/^[a-f0-9]{48}$/.test(value.id) || !/^[a-f0-9]{64}$/.test(value.digest) || value.operation !== args.operation
				|| value.toolName !== 'nc_files_' + args.operation || value.sourceResourceId !== args.sourceResourceId || value.history !== 'audit-only'
				|| typeof value.target !== 'string' || value.target.length > 4096 || typeof value.sourcePath !== 'string' || value.sourcePath.length > 4096
				|| typeof value.limitation !== 'string' || !value.limitation || value.limitation.length > 2048
				|| strongEtagHeader(value.expectedEtag) !== strongEtagHeader(args.expectedEtag) || !Number.isInteger(value.expires) || value.expires * 1000 <= Date.now()
				|| (args.operation === 'share_readonly' ? value.recipientUserId !== args.recipientUserId : value.destinationResourceId !== args.destinationResourceId || value.destinationName !== args.destinationName)) throw new Error('Invalid native Files preview.')
				signal.throwIfAborted()
				return value
			} catch (error) { cancel(); throw error } finally { signal.removeEventListener('abort', cancel) }
		},
		approve: async (proposal, signal) => { await post(`/files/operations/${proposal.id}/approve`, { digest: proposal.digest }, signal) },
		commit: async (proposal, signal) => {
			const result = await post<FileOperationOutcome>(`/files/operations/${proposal.id}/commit`, { digest: proposal.digest }, signal)
			if (!['verified', 'unknown', 'not-dispatched'].includes(result.outcome) || result.history !== 'audit-only'
				|| (result.outcome === 'verified' && !/^[a-f0-9]{32}$/.test(result.actionId ?? ''))) throw new Error('The native Files receipt is invalid. Inspect before trying again.')
			if (result.outcome === 'verified' && result.file) {
				const file = result.file; const source = Number(proposal.sourceResourceId.slice(16))
				if (!Number.isSafeInteger(file.id) || file.id < 1 || file.folder !== false || typeof file.etag !== 'string' || !file.etag
					|| typeof file.size !== 'number' || file.size < 0 || file.size > 10485760 || !Number.isInteger(file.permissions) || file.permissions < 0 || file.permissions > 31
					|| (proposal.operation === 'copy' ? file.id === source : file.id !== source)
					|| (proposal.operation === 'share_readonly' ? file.path !== proposal.sourcePath : file.name !== proposal.destinationName || file.path !== proposal.target)
					|| !/^nextcloud:files:[1-9][0-9]{0,14}$/.test(result.parentResourceId ?? '')) throw new Error('The native Files readback metadata is invalid. Inspect before trying again.')
				strongEtagHeader(file.etag)
			}
			return result
		},
		cancel: async proposal => { await cleanup(proposal.id) },
	}
}
