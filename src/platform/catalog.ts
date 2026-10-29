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

export type TablesToolName =
	| 'nc_tables_read'
	| 'nc_tables_add_column'
	| 'nc_tables_add_row'
	| 'nc_tables_update_row'
	| 'nc_tables_delete_row'

export type DeckToolName =
	| 'nc_deck_read'
	| 'nc_deck_patch'
	| 'nc_deck_create'
	| 'nc_deck_move'
	| 'nc_deck_assign'
	| 'nc_deck_label'

export type CalendarToolName =
	| 'nc_calendar_list'
	| 'nc_calendar_read'
	| 'nc_calendar_create'
	| 'nc_calendar_patch'
	| 'nc_calendar_delete'

export type TasksToolName =
	| 'nc_tasks_read'
	| 'nc_tasks_create'
	| 'nc_tasks_patch'
	| 'nc_tasks_delete'

export type ContactsToolName =
	| 'nc_contacts_list'
	| 'nc_contacts_find'
	| 'nc_contacts_read'
	| 'nc_contacts_create'
	| 'nc_contacts_patch'
	| 'nc_contacts_delete'

export type NextcloudToolName =
	| FileToolName
	| ExtendedFileToolName
	| TablesToolName
	| DeckToolName
	| CalendarToolName
	| TasksToolName
	| ContactsToolName

export interface FileToolCapability {
	readonly name: NextcloudToolName
	readonly subsystem: 'files' | 'tables' | 'deck' | 'calendar' | 'tasks' | 'contacts'
	readonly risk: ToolRisk
	readonly effect: FileEffect
	readonly individualApproval: boolean
	readonly source: 'included' | 'known-or-included' | 'discovery'
	readonly reversal: 'not-applicable' | 'not-integrated' | 'supported' | 'compensating-only'
}

function capability(name: NextcloudToolName, effect: FileEffect, source: FileToolCapability['source'], reversal?: FileToolCapability['reversal'], subsystem: 'files' | 'tables' | 'deck' | 'calendar' | 'tasks' | 'contacts' = 'files'): FileToolCapability {
	const mutation = effect === 'create-file' || effect === 'replace-file' || effect === 'reconcile-history' || effect === 'revalidate-history'
		|| effect === 'create-folder' || effect === 'copy-file' || effect === 'move-file' || effect === 'share-file'
	return Object.freeze({
		name,
		subsystem,
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
	capability('nc_files_create_folder', 'create-folder', 'known-or-included', 'not-integrated'),
	capability('nc_files_copy', 'copy-file', 'known-or-included', 'not-integrated'),
	capability('nc_files_move', 'move-file', 'known-or-included', 'not-integrated'),
	capability('nc_files_shares_read', 'metadata', 'known-or-included', 'not-applicable'),
	capability('nc_files_share_readonly', 'share-file', 'known-or-included', 'not-integrated'),
	capability('nc_files_organize_read', 'metadata', 'known-or-included', 'not-applicable'),
])

// NC09: Tables tools slice (five tested tools)
export const TABLES_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_tables_read', 'read-content', 'known-or-included', 'not-applicable', 'tables'),
	capability('nc_tables_add_column', 'create-file', 'known-or-included', 'not-integrated', 'tables'),
	capability('nc_tables_add_row', 'create-file', 'known-or-included', 'not-integrated', 'tables'),
	capability('nc_tables_update_row', 'replace-file', 'known-or-included', 'not-integrated', 'tables'),
	capability('nc_tables_delete_row', 'replace-file', 'known-or-included', 'not-integrated', 'tables'),
])

// NC09: Deck focused card tools slice
export const DECK_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_deck_read', 'read-content', 'known-or-included', 'not-applicable', 'deck'),
	capability('nc_deck_patch', 'replace-file', 'known-or-included', 'not-integrated', 'deck'),
	capability('nc_deck_create', 'create-file', 'known-or-included', 'not-integrated', 'deck'),
	capability('nc_deck_move', 'move-file', 'known-or-included', 'not-integrated', 'deck'),
	capability('nc_deck_assign', 'share-file', 'known-or-included', 'not-integrated', 'deck'),
	capability('nc_deck_label', 'replace-file', 'known-or-included', 'not-integrated', 'deck'),
])

// NC10: Calendar tools slice (private events)
export const CALENDAR_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_calendar_list', 'read-content', 'known-or-included', 'not-applicable', 'calendar'),
	capability('nc_calendar_read', 'read-content', 'known-or-included', 'not-applicable', 'calendar'),
	capability('nc_calendar_create', 'create-file', 'known-or-included', 'not-integrated', 'calendar'),
	capability('nc_calendar_patch', 'replace-file', 'known-or-included', 'not-integrated', 'calendar'),
	capability('nc_calendar_delete', 'replace-file', 'known-or-included', 'not-integrated', 'calendar'),
])

// NC10: Tasks tools slice
export const TASKS_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_tasks_read', 'read-content', 'known-or-included', 'not-applicable', 'tasks'),
	capability('nc_tasks_create', 'create-file', 'known-or-included', 'not-integrated', 'tasks'),
	capability('nc_tasks_patch', 'replace-file', 'known-or-included', 'not-integrated', 'tasks'),
	capability('nc_tasks_delete', 'replace-file', 'known-or-included', 'not-integrated', 'tasks'),
])

// NC10: Contacts tools slice
export const CONTACTS_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_contacts_list', 'read-content', 'known-or-included', 'not-applicable', 'contacts'),
	capability('nc_contacts_find', 'read-content', 'known-or-included', 'not-applicable', 'contacts'),
	capability('nc_contacts_read', 'read-content', 'known-or-included', 'not-applicable', 'contacts'),
	capability('nc_contacts_create', 'create-file', 'known-or-included', 'not-integrated', 'contacts'),
	capability('nc_contacts_patch', 'replace-file', 'known-or-included', 'not-integrated', 'contacts'),
	capability('nc_contacts_delete', 'replace-file', 'known-or-included', 'not-integrated', 'contacts'),
])

export function fileCapability(name: string): FileToolCapability | undefined {
	return [
		...FILE_CAPABILITIES,
		...HISTORY_CAPABILITIES,
		...EXTENDED_FILE_CAPABILITIES,
		...TABLES_CAPABILITIES,
		...DECK_CAPABILITIES,
		...CALENDAR_CAPABILITIES,
		...TASKS_CAPABILITIES,
		...CONTACTS_CAPABILITIES,
	].find(tool => tool.name === name)
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
