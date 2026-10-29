import type { FolderCreateOutcome, FolderCreateProposal } from './types.ts'
import { nativeFileLink } from './fileReadResults.ts'

export function folderProposal(value: FolderCreateProposal, reference: string, name: string, revision: string): FolderCreateProposal {
	if (!value || typeof value.id !== 'string' || !/^[a-zA-Z0-9_-]{1,128}$/.test(value.id)
		|| value.toolName !== 'nc_files_create_folder' || value.resourceId !== reference
		|| !Number.isSafeInteger(value.folderId) || reference !== `nextcloud:files:${value.folderId}`
		|| value.name !== name || value.parentEtag !== revision || value.expectedRevision !== revision
		|| typeof value.target !== 'string' || !value.target.startsWith('/') || !value.target.endsWith(`/${name}`)
		|| /[\x00-\x1f\x7f]/.test(value.target) || value.target.split('/').some(part => part === '.' || part === '..')
		|| value.target.length > 4096 || value.approved !== false || !Number.isSafeInteger(value.expires)
		|| value.expires <= Math.floor(Date.now() / 1000) || value.expires > Math.floor(Date.now() / 1000) + 610) throw new Error('Invalid native folder proposal.')
	return structuredClone(value)
}

export function folderOutcome(value: FolderCreateOutcome, proposal: FolderCreateProposal): FolderCreateOutcome {
	if (!value || value.proposalId !== proposal.id || value.history !== 'not-integrated'
		|| value.name !== proposal.name || value.parentResourceId !== proposal.resourceId) throw new Error('Invalid native folder receipt. Inspect the destination before trying again.')
	if (value.outcome === 'not-dispatched' || value.outcome === 'ambiguous') {
		if (value.target !== proposal.target || typeof value.message !== 'string' || !value.message || value.message.length > 1000) throw new Error('Invalid native folder receipt.')
		return { outcome: value.outcome, history: value.history, proposalId: value.proposalId, name: value.name, parentResourceId: value.parentResourceId, target: value.target, message: value.message }
	}
	if (value.outcome !== 'verified' || !value.folder || !value.observedState
		|| !Number.isSafeInteger(value.folder.id) || value.folder.id < 1 || value.resourceId !== `nextcloud:files:${value.folder.id}`
		|| value.folder.id === proposal.folderId || !value.folder.folder || value.folder.name !== proposal.name
		|| value.folder.path !== proposal.target || !value.folder.etag
		|| (value.parentPath !== proposal.target.slice(0, -(proposal.name.length + 1)) && !(value.parentPath === '/' && proposal.target === `/${proposal.name}`))
		|| value.observedState.id !== value.folder.id || value.observedState.name !== proposal.name || value.observedState.path !== proposal.target
		|| value.observedState.etag !== value.folder.etag || value.observedState.is_directory !== true) throw new Error('Invalid native folder receipt. Inspect the destination before trying again.')
	if (typeof value.folder.etag !== 'string' || value.folder.etag.length > 150
		|| !Number.isInteger(value.folder.permissions) || value.folder.permissions < 0 || value.folder.permissions > 31
		|| value.observedState.permissions !== value.folder.permissions || !Number.isFinite(value.folder.size) || value.folder.size < -1) throw new Error('Invalid native folder metadata.')
	return {
		outcome: 'verified',
		history: 'not-integrated',
		proposalId: proposal.id,
		name: proposal.name,
		resourceId: value.resourceId,
		parentResourceId: proposal.resourceId,
		parentPath: value.parentPath,
		folder: { id: value.folder.id, name: proposal.name, path: proposal.target, etag: value.folder.etag, mime: 'httpd/unix-directory', size: value.folder.size, folder: true, permissions: value.folder.permissions, url: nativeFileLink(value.resourceId) ?? '' },
		observedState: { id: value.folder.id, name: proposal.name, path: proposal.target, etag: value.folder.etag, permissions: value.folder.permissions, is_directory: true },
	}
}
