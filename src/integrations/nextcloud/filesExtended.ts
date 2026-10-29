import { defineTool, type ToolCallContext } from '@fadenstack/agents'
import type { JsonSchema } from '@fadenstack/client'
import type { FileApi, FileContext, ShareEntry } from './types.ts'

export interface ExtendedProposal {
	name: string
	args: Readonly<Record<string, unknown>>
	target: string
	before?: unknown
	after?: unknown
	revision: string
}

export interface ExtendedFilesHost {
	files: FileApi
	context?(): FileContext
	recordResult(callId: string, value: unknown): void
	resolvePath?(pathOrId: unknown, signal?: AbortSignal): Promise<{ path: string; etag: string; folder: boolean }>
	proposeMutation?(name: string, args: Readonly<Record<string, unknown>>, callId: string, signal: AbortSignal): Promise<ExtendedProposal>
	commitMutation?(name: string, args: Readonly<Record<string, unknown>>, call: ToolCallContext): Promise<unknown>
	authorizeSource?(reference: string, signal: AbortSignal, purpose?: 'read' | 'metadata'): Promise<void>
	rememberSource?(reference: string, signal: AbortSignal): Promise<void>
}

export function validateFolderName(name: unknown): string {
	if (typeof name !== 'string' || !name.trim() || name.length > 100 || /[\x00-\x1f\x7f/\\:<>"|?*]/.test(name) || name === '.' || name === '..') {
		throw new Error('A folder name of 1–100 characters without path separators or special characters is required.')
	}
	return name.trim()
}

export function validateCopyMoveArgs(args: Readonly<Record<string, unknown>>): { source: string; destination: string; expectedEtag: string } {
	const source = String(args.source ?? args.source_resource_id ?? '').trim()
	const destination = String(args.destination ?? args.destination_name ?? '').trim()
	const expectedEtag = String(args.expectedEtag ?? args.expected_etag ?? '').trim()
	if (!source) throw new Error('A source path or resource ID is required.')
	if (!destination || /[\x00-\x1f\x7f/\\:<>"|?*]/.test(destination) || destination === '.' || destination === '..') {
		throw new Error('A destination filename without path separators is required.')
	}
	if (!expectedEtag) throw new Error('The expected source ETag is required to prevent overwriting modified data.')
	return { source, destination, expectedEtag }
}

export function validateShareReadonlyArgs(args: Readonly<Record<string, unknown>>): { path: string; recipientUserId: string; expectedEtag: string } {
	const path = String(args.path ?? args.filename ?? args.resource_id ?? '').trim()
	const recipientUserId = String(args.recipientUserId ?? args.recipient_user_id ?? '').trim()
	const expectedEtag = String(args.expectedEtag ?? args.expected_etag ?? '').trim()
	if (!path) throw new Error('A target file path or resource ID is required.')
	if (!recipientUserId || recipientUserId.length > 80 || /[\x00-\x1f\x7f/\\:<>"|?*]/.test(recipientUserId)) {
		throw new Error('A valid recipient Nextcloud user ID is required.')
	}
	if (!expectedEtag) throw new Error('The expected file ETag is required.')
	return { path, recipientUserId, expectedEtag }
}

export const EXTENDED_FILES_MUTATIONS = new Set([
	'nc_files_create_folder',
	'nc_files_copy',
	'nc_files_move',
	'nc_files_share_readonly',
])

export function filesReadTools(host: ExtendedFilesHost) {
	return [
		defineTool({
			name: 'nc_files_organize_read',
			toolClass: 'read',
			description: 'List the contents, folder status and native ETags of the included folder or a specified directory before organizing or sharing. Does not read file contents.',
			parameters: {
				type: 'object',
				properties: {
					resource_id: { type: 'string', description: 'Optional folder path or nextcloud:files:ID. If omitted, uses active folder.' },
					expectedRevision: { type: 'string', description: 'Optional expected folder ETag for optimistic concurrency.' },
				},
				additionalProperties: false,
			},
			run: async (args, c) => {
				let targetId: string | undefined
				if (args.resource_id !== undefined && args.resource_id !== null && String(args.resource_id).trim() !== '') {
					const raw = String(args.resource_id).trim()
					if (!/^nextcloud:files:[1-9][0-9]{0,14}$/.test(raw)) {
						throw new Error('Use a Nextcloud file resource ID, not a path or URL.')
					}
					targetId = raw
				} else if (host.context) {
					const ctx = host.context()
					if (!ctx.file.folder) {
						throw new Error('Folder organization read requires a folder context or folder resource_id, not a selected file.')
					}
					targetId = `nextcloud:files:${ctx.file.id}`
				}
				if (!host.files.organizeRead) throw new Error('Folder organization read is unavailable.')
				if (targetId && host.authorizeSource) {
					await host.authorizeSource(targetId, c.signal, 'read')
				}
				const expectedRevision = typeof args.expectedRevision === 'string' ? args.expectedRevision : undefined
				const listing = expectedRevision !== undefined
					? await host.files.organizeRead(targetId, expectedRevision, c.signal)
					: await host.files.organizeRead(targetId, c.signal)
				if (listing?.entries && host.rememberSource) {
					for (const entry of listing.entries) {
						if (entry.resourceId) {
							await host.rememberSource(entry.resourceId, c.signal)
						}
					}
				}
				return listing
			},
		}),
		defineTool({
			name: 'nc_files_shares_read',
			toolClass: 'read',
			description: 'Inspect access and existing shares for a file or folder. Returns share IDs, recipient user/group names and permissions. Reusable share passwords and secrets are never returned.',
			parameters: {
				type: 'object',
				properties: {
					resource_id: { type: 'string', description: 'The file or folder path or nextcloud:files:ID to inspect.' },
					filename: { type: 'string', description: 'Alternative simple filename in the current directory.' },
					expectedRevision: { type: 'string', description: 'Optional expected resource ETag for optimistic concurrency.' },
				},
				additionalProperties: false,
			},
			run: async (args, c) => {
				let target = String(args.resource_id ?? args.filename ?? '').trim()
				if (!target && host.context) {
					target = `nextcloud:files:${host.context().file.id}`
				}
				if (!target) throw new Error('A target file or folder is required.')
				if (target.startsWith('nextcloud:files:')) {
					if (!/^nextcloud:files:[1-9][0-9]{0,14}$/.test(target)) {
						throw new Error('Use a Nextcloud file resource ID, not a path or URL.')
					}
					if (host.authorizeSource) {
						await host.authorizeSource(target, c.signal, 'read')
					}
				} else if (/^[a-zA-Z0-9_.-]+$/.test(target)) {
					// legacy/test simple filename support
				} else {
					throw new Error('Use a Nextcloud file resource ID, not a path or URL.')
				}
				if (!host.files.sharesRead) throw new Error('Share inspection is unavailable.')
				const expectedRevision = typeof args.expectedRevision === 'string' ? args.expectedRevision : undefined
				const shares = expectedRevision !== undefined
					? await host.files.sharesRead(target, expectedRevision, c.signal)
					: await host.files.sharesRead(target, c.signal)
				return shares.map(s => {
					const sanitized: ShareEntry = {
						id: Number(s.id),
						shareType: Number(s.shareType),
						permissions: Number(s.permissions),
						path: s.path,
						mailSend: s.mailSend === true,
					}
					if (s.shareWith !== undefined) sanitized.shareWith = String(s.shareWith)
					if (s.shareWithDisplayName !== undefined) sanitized.shareWithDisplayName = String(s.shareWithDisplayName)
					if (s.fileTarget !== undefined) sanitized.fileTarget = String(s.fileTarget)
					if (s.expiration !== undefined) sanitized.expiration = s.expiration
					if (s.inherited !== undefined) sanitized.inherited = s.inherited
					return sanitized
				})
			},
		}),
	]
}

export function filesMutationTools(host: ExtendedFilesHost) {
	const textSchema: JsonSchema = { type: 'string', minLength: 1, maxLength: 200 }
	const nameSchema: JsonSchema = { type: 'string', minLength: 1, maxLength: 100, pattern: '^[^/\\\\?#:<>"|*]+$' }
	const etagSchema: JsonSchema = { type: 'string', minLength: 1, maxLength: 150 }

	return [
		defineTool({
			name: 'nc_files_create_folder',
			toolClass: 'destructive',
			description: 'Create one empty folder inside the active directory or target parent folder after individual approval. Existing folders are not overwritten.',
			parameters: {
				type: 'object',
				properties: {
					name: nameSchema,
					resource_id: { type: 'string', description: 'Optional parent folder path or nextcloud:files:ID.' },
				},
				required: ['name'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				validateFolderName(args.name)
				if (host.commitMutation) return host.commitMutation('nc_files_create_folder', args, c)
				const parent = String(args.resource_id ?? (host.context ? host.context().file.path : ''))
				if (!host.files.createFolder) throw new Error('Folder creation is unavailable.')
				return host.files.createFolder(parent, String(args.name), c.signal)
			},
		}),
		defineTool({
			name: 'nc_files_copy',
			toolClass: 'destructive',
			description: 'Copy one file to a new destination without overwriting existing files. Requires source ETag and individual approval. Source file remains unchanged.',
			parameters: {
				type: 'object',
				properties: {
					source: textSchema,
					destination: nameSchema,
					expectedEtag: etagSchema,
					source_resource_id: textSchema,
					destination_name: nameSchema,
					expected_etag: etagSchema,
				},
				additionalProperties: false,
			},
			run: async (args, c) => {
				const validated = validateCopyMoveArgs(args)
				if (host.commitMutation) return host.commitMutation('nc_files_copy', args, c)
				if (!host.files.copyFile) throw new Error('File copying is unavailable.')
				return host.files.copyFile(validated.source, validated.destination, validated.expectedEtag, c.signal)
			},
		}),
		defineTool({
			name: 'nc_files_move',
			toolClass: 'destructive',
			description: 'Move or rename one file to a new destination without overwriting existing files. Requires source ETag and individual approval.',
			parameters: {
				type: 'object',
				properties: {
					source: textSchema,
					destination: nameSchema,
					expectedEtag: etagSchema,
					source_resource_id: textSchema,
					destination_name: nameSchema,
					expected_etag: etagSchema,
				},
				additionalProperties: false,
			},
			run: async (args, c) => {
				const validated = validateCopyMoveArgs(args)
				if (host.commitMutation) return host.commitMutation('nc_files_move', args, c)
				if (!host.files.moveFile) throw new Error('File moving is unavailable.')
				return host.files.moveFile(validated.source, validated.destination, validated.expectedEtag, c.signal)
			},
		}),
		defineTool({
			name: 'nc_files_share_readonly',
			toolClass: 'destructive',
			description: 'Create an explicitly approved read-only share of one file with an authorized Nextcloud user ID. Email notifications are disabled and link sharing is excluded.',
			parameters: {
				type: 'object',
				properties: {
					path: textSchema,
					filename: nameSchema,
					resource_id: textSchema,
					recipientUserId: textSchema,
					recipient_user_id: textSchema,
					expectedEtag: etagSchema,
					expected_etag: etagSchema,
				},
				additionalProperties: false,
			},
			run: async (args, c) => {
				const validated = validateShareReadonlyArgs(args)
				if (host.commitMutation) return host.commitMutation('nc_files_share_readonly', args, c)
				if (!host.files.shareReadonly) throw new Error('Read-only sharing is unavailable.')
				return host.files.shareReadonly(validated.path, validated.recipientUserId, validated.expectedEtag, c.signal)
			},
		}),
	]
}

export function filesExtendedTools(host: ExtendedFilesHost) {
	return [...filesReadTools(host), ...filesMutationTools(host)]
}
