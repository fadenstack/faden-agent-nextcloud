/** Host restrictions for the existing Files surface. This catalog never grants a central tool. */
export type ToolRisk = 'read' | 'write' | 'destructive'
export type FileEffect =
	| 'metadata' | 'read-content' | 'create-file' | 'replace-file'
	| 'reconcile-history' | 'revalidate-history'
	| 'create-folder' | 'copy-file' | 'move-file' | 'share-file'

export type FileToolName =
	| 'nc_get_file_metadata' | 'nc_search_in_file' | 'nc_search_content'
	| 'nc_search_files' | 'nc_get_selected_file' | 'nc_read_file'
	| 'nc_list_folder' | 'nc_create_text_file' | 'nc_update_text_file'
	| 'nc_actions_undo' | 'nc_actions_redo' | 'nc_actions_reconcile' | 'nc_actions_revalidate'

export type ExtendedFileToolName =
	| 'nc_files_create_folder'
	| 'nc_files_copy'
	| 'nc_files_move'
	| 'nc_files_shares_read'
	| 'nc_files_share_readonly'
	| 'nc_files_organize_read'

export type NextcloudToolName = FileToolName | ExtendedFileToolName

export interface FileToolCapability {
	readonly name: NextcloudToolName
	readonly subsystem: 'files'
	readonly risk: ToolRisk
	readonly effect: FileEffect
	readonly individualApproval: boolean
	readonly source: 'included' | 'known-or-included' | 'discovery'
	readonly reversal: 'not-applicable' | 'not-integrated' | 'supported' | 'compensating-only'
}

function capability(name: NextcloudToolName, effect: FileEffect, source: FileToolCapability['source'], reversal?: FileToolCapability['reversal']): FileToolCapability {
	const mutation = effect === 'create-file' || effect === 'replace-file' || effect === 'reconcile-history' || effect === 'revalidate-history'
		|| effect === 'create-folder' || effect === 'copy-file' || effect === 'move-file' || effect === 'share-file'
	return Object.freeze({
		name,
		subsystem: 'files',
		risk: mutation ? 'destructive' : 'read',
		effect,
		individualApproval: mutation,
		source,
		reversal: reversal ?? (effect === 'reconcile-history' || effect === 'revalidate-history' ? 'not-applicable' : mutation ? 'not-integrated' : 'not-applicable'),
	})
}

// Preserve these published names and their existing stricter mutation classification.
export const FILE_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_get_file_metadata', 'metadata', 'known-or-included'),
	capability('nc_search_in_file', 'read-content', 'known-or-included'),
	capability('nc_search_content', 'read-content', 'discovery'),
	capability('nc_search_files', 'metadata', 'discovery'),
	capability('nc_get_selected_file', 'metadata', 'included'),
	capability('nc_read_file', 'read-content', 'known-or-included'),
	capability('nc_list_folder', 'metadata', 'included'),
	capability('nc_create_text_file', 'create-file', 'known-or-included'),
	capability('nc_update_text_file', 'replace-file', 'known-or-included'),
])

// Opt-in tools are registered separately; reviewing the legacy surface enables none.
export const HISTORY_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_actions_undo', 'replace-file', 'known-or-included'),
	capability('nc_actions_redo', 'replace-file', 'known-or-included'),
	capability('nc_actions_reconcile', 'reconcile-history', 'known-or-included'),
	capability('nc_actions_revalidate', 'revalidate-history', 'known-or-included'),
])

// NC08: Extended Files folder, copy/move and read-only sharing slice
export const EXTENDED_FILE_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_files_create_folder', 'create-folder', 'known-or-included', 'supported'),
	capability('nc_files_copy', 'copy-file', 'known-or-included', 'supported'),
	capability('nc_files_move', 'move-file', 'known-or-included', 'supported'),
	capability('nc_files_shares_read', 'metadata', 'known-or-included', 'not-applicable'),
	capability('nc_files_share_readonly', 'share-file', 'known-or-included', 'supported'),
	capability('nc_files_organize_read', 'metadata', 'included', 'not-applicable'),
])

export function fileCapability(name: string): FileToolCapability | undefined {
	return [...FILE_CAPABILITIES, ...HISTORY_CAPABILITIES, ...EXTENDED_FILE_CAPABILITIES].find(tool => tool.name === name)
}

export function stricterRisk(host: ToolRisk, published: ToolRisk): ToolRisk {
	const ranks: Record<ToolRisk, number> = { read: 0, write: 1, destructive: 2 }
	return ranks[host] >= ranks[published] ? host : published
}

/** Research disposition is planning traceability, never an executable tool list. */
export const TOOL_ROLLOUT = Object.freeze({
	manifestVersion: 1,
	enabledSubsystems: Object.freeze(['files'] as const),
	expandedCatalog: 'not-enabled' as const,
	dispositionSource: 'fadenstack-dev/nextcloud-agent-implementation/tool-rollout.csv',
})
