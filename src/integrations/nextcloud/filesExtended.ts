import { defineTool, type ToolCallContext } from '@fadenstack/agents'
import type { JsonSchema } from '@fadenstack/client'
import type { FileApi, FileContext, ShareEntry, FolderOverviewResults, ShareOverviewResults, FileOperationProposal } from './types.ts'
import { fileReference } from '../../platform/scope.ts'
import { folderReadResult } from './fileReadResults.ts'

export interface ExtendedProposal {
	name: string
	args: Readonly<Record<string, unknown>>
	target: string
	before?: unknown
	after?: unknown
	revision: string
	native?: FileOperationProposal
}

export interface ExtendedFilesHost {
	files: FileApi
	context?(): FileContext
	recordResult(callId: string, value: unknown, context: ToolCallContext): void
	resolvePath?(pathOrId: unknown, signal?: AbortSignal): Promise<{ path: string; etag: string; folder: boolean }>
	proposeMutation?(name: string, args: Readonly<Record<string, unknown>>, callId: string, signal: AbortSignal): Promise<ExtendedProposal>
	commitMutation?(name: string, args: Readonly<Record<string, unknown>>, call: ToolCallContext): Promise<unknown>
	authorizeSource?(reference: string, signal: AbortSignal, purpose?: 'read' | 'metadata' | 'shares'): Promise<void>
	rememberSource?(reference: string, signal: AbortSignal): Promise<void>
}

export function validateFolderName(name: unknown): string {
	if (typeof name !== 'string' || !name || name !== name.trim() || Array.from(name).length > 100 || new TextEncoder().encode(name).byteLength > 255 || /[\x00-\x1f\x7f/\\:<>"|?*]/.test(name) || name === '.' || name === '..' || new TextDecoder().decode(new TextEncoder().encode(name)) !== name) {
		throw new Error('A folder name of 1–100 characters without path separators or special characters is required.')
	}
	return name
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

function readRevision(args: Readonly<Record<string, unknown>>): string | undefined {
	if (Object.keys(args).some(key => !['resource_id', 'expectedRevision'].includes(key))) throw new Error('Use a canonical resource_id and optional expectedRevision.')
	const value = args.expectedRevision
	if (value !== undefined && (typeof value !== 'string' || !value || value.length > 150 || /[\x00-\x1f\x7f]/.test(value))) throw new Error('A valid expectedRevision is required.')
	return value as string | undefined
}

export function filesReadTools(host: ExtendedFilesHost) {
	return [
		defineTool({
			name: 'nc_files_organize_read',
			toolClass: 'read',
			description: 'List the contents, folder status and native ETags of the included folder or a specified directory before organizing or sharing. Does not read file contents.',
			parameters: {
				type: 'object',
				properties: {
					resource_id: { type: 'string', description: 'A canonical nextcloud:files:ID for a known folder. If omitted, requires an included folder.' },
					expectedRevision: { type: 'string', description: 'Optional expected folder ETag for optimistic concurrency.' },
				},
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const expectedRevision = readRevision(args)
				let targetId: string | undefined
				if (args.resource_id !== undefined) {
					const raw = args.resource_id
					if (typeof raw !== 'string' || !/^nextcloud:files:[1-9][0-9]{0,14}$/.test(raw)) {
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
				if (!targetId) throw new Error('Include a folder or provide a known folder resource_id.')
				fileReference(targetId)
				if (!host.files.organizeRead) throw new Error('Folder organization read is unavailable.')
				if (targetId && host.authorizeSource) {
					await host.authorizeSource(targetId, c.signal, 'read')
				}
				const raw = expectedRevision !== undefined
					? await host.files.organizeRead(targetId, expectedRevision, c.signal)
					: await host.files.organizeRead(targetId, c.signal)
				c.signal.throwIfAborted()
				const listing = folderReadResult(raw, targetId)
				if (listing?.entries && host.rememberSource) {
					for (const entry of listing.entries) {
						if (entry.resourceId) {
							await host.rememberSource(entry.resourceId, c.signal)
						}
					}
				}
				c.signal.throwIfAborted()
				const result: FolderOverviewResults = {
					kind: 'nextcloud-folder-overview',
					directory: listing.directory,
					resourceId: targetId,
					etag: listing.etag,
					entries: listing.entries,
					partial: listing.partial!,
				}
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
		defineTool({
			name: 'nc_files_shares_read',
			toolClass: 'read',
			description: 'Inspect current-user user/group/public-link shares for a known file or folder, including inherited and expired shares. Coverage is partial; this is not a complete access inventory. Reusable link tokens and passwords are never returned.',
			parameters: {
				type: 'object',
				properties: {
					resource_id: { type: 'string', description: 'A canonical nextcloud:files:ID for a known file or folder. If omitted, uses the included resource.' },
					expectedRevision: { type: 'string', description: 'Optional expected resource ETag for optimistic concurrency.' },
				},
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const expectedRevision = readRevision(args)
				const target = args.resource_id !== undefined ? args.resource_id : (host.context ? `nextcloud:files:${host.context().file.id}` : undefined)
				if (typeof target !== 'string' || !/^nextcloud:files:[1-9][0-9]{0,14}$/.test(target)) {
					throw new Error('Use a Nextcloud file resource ID, not a path or URL.')
				}
				fileReference(target)
				await host.authorizeSource?.(target, c.signal, 'shares')
				if (!host.files.sharesRead) throw new Error('Share inspection is unavailable.')
				const listing = expectedRevision !== undefined
					? await host.files.sharesRead(target, expectedRevision, c.signal)
					: await host.files.sharesRead(target, c.signal)
				c.signal.throwIfAborted()
				if (listing.resourceId !== target || !listing.etag || !Array.isArray(listing.shares) || listing.shares.length > 50
					|| typeof listing.complete !== 'boolean' || typeof listing.coverage !== 'string') throw new Error('Invalid native share response.')
				const shares = listing.shares.map(s => {
					if (!Number.isSafeInteger(s.id) || s.id < 1 || ![0, 1, 3].includes(s.shareType)
						|| !Number.isInteger(s.permissions) || s.permissions < 0 || s.permissions > 31) throw new Error('Invalid native share metadata.')
					const sanitized: ShareEntry = {
						id: Number(s.id),
						shareType: Number(s.shareType),
						permissions: Number(s.permissions),
						path: s.path,
						mailSend: s.mailSend === true,
					}
					if (s.shareType === 3) {
						sanitized.shareWith = 'public-link'
						sanitized.shareWithDisplayName = 'Public link'
					} else {
						if (s.shareWith !== undefined) sanitized.shareWith = String(s.shareWith)
						if (s.shareWithDisplayName !== undefined) sanitized.shareWithDisplayName = String(s.shareWithDisplayName)
					}
					if (s.expiration !== undefined) sanitized.expiration = s.expiration
					if (s.inherited !== undefined) sanitized.inherited = s.inherited
					return sanitized
				})
				const result: ShareOverviewResults = {
					kind: 'nextcloud-share-overview',
					resourceId: target,
					etag: listing.etag,
					shares,
					complete: listing.complete,
					coverage: listing.coverage,
				}
				c.signal.throwIfAborted()
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
	]
}

export function filesFolderCreateTool(host: ExtendedFilesHost) {
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
					resource_id: { type: 'string', description: 'Canonical nextcloud:files:ID for the parent folder. If omitted, uses the explicitly included folder.' },
					expectedRevision: etagSchema,
				},
				required: ['name'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				validateFolderName(args.name)
				if (host.commitMutation) {
					const result = await host.commitMutation('nc_files_create_folder', args, c)
					// A finished request is not a confirmed effect. Keep the native
					// receipt/activity, while the shared SDK row shows failure rather
					// than a success checkmark for an unconfirmed or blocked action.
					if (result && typeof result === 'object' && 'outcome' in result && result.outcome !== 'verified') {
						const message = 'message' in result && typeof result.message === 'string' ? result.message : 'Inspect the destination before another attempt.'
						throw new Error(`${result.outcome === 'not-dispatched' ? 'Folder creation was not dispatched.' : 'Folder creation is unconfirmed.'} ${message}`)
					}
					return result
				}
				const parent = String(args.resource_id ?? (host.context ? host.context().file.path : ''))
				if (!host.files.createFolder) throw new Error('Folder creation is unavailable.')
				return host.files.createFolder(parent, String(args.name), c.signal)
			},
		}),
	]
}

export function filesMutationTools(host: ExtendedFilesHost) {
	const textSchema: JsonSchema = { type: 'string', minLength: 1, maxLength: 200 }
	const nameSchema: JsonSchema = { type: 'string', minLength: 1, maxLength: 100, pattern: '^[^/\\\\?#:<>"|*]+$' }
	const etagSchema: JsonSchema = { type: 'string', minLength: 1, maxLength: 150 }
	const native = !!host.files.fileOperations

	return [
		defineTool({
			name: 'nc_files_copy',
			toolClass: 'destructive',
			description: 'Copy one file to a new destination without overwriting existing files. Requires source ETag and individual approval. Source file remains unchanged.',
			parameters: {
				type: 'object',
				properties: {
					...(!native ? { source: textSchema, destination: nameSchema, expectedEtag: etagSchema } : {}),
					source_resource_id: textSchema,
					destination_name: nameSchema,
					expected_etag: etagSchema,
					destination_resource_id: textSchema,
				},
				...(native ? { required: ['source_resource_id', 'destination_resource_id', 'destination_name', 'expected_etag'] } : {}),
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
					...(!native ? { source: textSchema, destination: nameSchema, expectedEtag: etagSchema } : {}),
					source_resource_id: textSchema,
					destination_name: nameSchema,
					expected_etag: etagSchema,
					destination_resource_id: textSchema,
				},
				...(native ? { required: ['source_resource_id', 'destination_resource_id', 'destination_name', 'expected_etag'] } : {}),
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
					...(!native ? { path: textSchema, filename: nameSchema, recipientUserId: textSchema, expectedEtag: etagSchema } : {}),
					resource_id: textSchema,
					recipient_user_id: textSchema,
					expected_etag: etagSchema,
				},
				...(native ? { required: ['resource_id', 'recipient_user_id', 'expected_etag'] } : {}),
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
	return [...filesReadTools(host), ...filesFolderCreateTool(host), ...filesMutationTools(host)]
}
