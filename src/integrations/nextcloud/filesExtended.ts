import { defineTool, type ToolCallContext } from '@fadenstack/agents'
import type { JsonSchema } from '@fadenstack/client'
import type { FileApi, FileContext } from './types.ts'

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

export function filesExtendedTools(host: ExtendedFilesHost) {
	const textSchema: JsonSchema = { type: 'string', minLength: 1, maxLength: 200 }
	const nameSchema: JsonSchema = { type: 'string', minLength: 1, maxLength: 100, pattern: '^[^/\\\\?#:<>"|*]+$' }
	const etagSchema: JsonSchema = { type: 'string', minLength: 1, maxLength: 150 }

	return [
		defineTool({
			name: 'nc_files_organize_read',
			toolClass: 'read',
			description: 'List the contents, folder status and native ETags of the included folder or a specified directory before organizing or sharing. Does not read file contents.',
			parameters: {
				type: 'object',
				properties: {
					resource_id: { type: 'string', description: 'Optional folder path or nextcloud:files:ID. If omitted, uses active folder.' },
				},
				additionalProperties: false,
			},
			run: async (args, c) => {
				const folderPath = String(args.resource_id ?? (host.context ? host.context().file.path : '')).replace(/^\/+/, '')
				if (!host.files.organizeRead) throw new Error('Folder organization read is unavailable.')
				return host.files.organizeRead(folderPath, c.signal)
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
				},
				additionalProperties: false,
			},
			run: async (args, c) => {
				const target = String(args.resource_id ?? args.filename ?? (host.context ? host.context().file.path : '')).trim()
				if (!target) throw new Error('A target file or folder is required.')
				if (!host.files.sharesRead) throw new Error('Share inspection is unavailable.')
				const shares = await host.files.sharesRead(target, c.signal)
				return shares.map(s => ({
					id: s.id,
					shareType: s.shareType,
					shareWith: s.shareWith,
					shareWithDisplayName: s.shareWithDisplayName,
					permissions: s.permissions,
					path: s.path,
					fileTarget: s.fileTarget,
					expiration: s.expiration,
					mailSend: s.mailSend,
				}))
			},
		}),
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
