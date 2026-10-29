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

export type MailToolName =
	| 'nc_mail_read'
	| 'nc_mail_save_draft'
	| 'nc_mail_send_draft'

export type TalkToolName =
	| 'nc_talk_read'
	| 'nc_talk_send_message'

export type CollectivesToolName =
	| 'nc_collectives_read'
	| 'nc_collectives_create_page'
	| 'nc_collectives_delete_page'

export type FormsToolName =
	| 'nc_forms_read'
	| 'nc_forms_create_form'
	| 'nc_forms_submit_response'

export type WhiteboardToolName =
	| 'nc_whiteboard_read'
	| 'nc_whiteboard_create'

export type FlowToolName =
	| 'nc_flow_read'
	| 'nc_flow_create_draft'
	| 'nc_flow_update_rule'

export type WindmillToolName =
	| 'nc_windmill_read'
	| 'nc_windmill_draft'
	| 'nc_windmill_run'

export type N8nToolName =
	| 'nc_n8n_read'
	| 'nc_n8n_draft'
	| 'nc_n8n_execute'

export type AgentfileToolName =
	| 'nc_agentfile_read'

export type NotesToolName =
	| 'nc_notes_read'
	| 'nc_notes_create'

export type BookmarksToolName =
	| 'nc_bookmarks_read'
	| 'nc_bookmarks_create'

export type NextcloudToolName =
	| FileToolName
	| ExtendedFileToolName
	| TablesToolName
	| DeckToolName
	| CalendarToolName
	| TasksToolName
	| ContactsToolName
	| MailToolName
	| TalkToolName
	| CollectivesToolName
	| FormsToolName
	| WhiteboardToolName
	| FlowToolName
	| WindmillToolName
	| N8nToolName
	| AgentfileToolName
	| NotesToolName
	| BookmarksToolName

export type ToolSubsystem =
	| 'files'
	| 'tables'
	| 'deck'
	| 'calendar'
	| 'tasks'
	| 'contacts'
	| 'mail'
	| 'talk'
	| 'collectives'
	| 'forms'
	| 'whiteboard'
	| 'flow'
	| 'windmill'
	| 'n8n'
	| 'agentfile'
	| 'notes'
	| 'bookmarks'

export interface FileToolCapability {
	readonly name: NextcloudToolName
	readonly subsystem: ToolSubsystem
	readonly risk: ToolRisk
	readonly effect: FileEffect
	readonly individualApproval: boolean
	readonly source: 'included' | 'known-or-included' | 'discovery'
	readonly reversal: 'not-applicable' | 'not-integrated' | 'supported' | 'compensating-only'
}

function capability(name: NextcloudToolName, effect: FileEffect, source: FileToolCapability['source'], reversal?: FileToolCapability['reversal'], subsystem: ToolSubsystem = 'files'): FileToolCapability {
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

// NC11 / NC12: Mail tools slice
export const MAIL_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_mail_read', 'read-content', 'known-or-included', 'not-applicable', 'mail'),
	capability('nc_mail_save_draft', 'create-file', 'known-or-included', 'not-integrated', 'mail'),
	capability('nc_mail_send_draft', 'replace-file', 'known-or-included', 'compensating-only', 'mail'),
])

// NC12: Talk tools slice
export const TALK_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_talk_read', 'read-content', 'known-or-included', 'not-applicable', 'talk'),
	capability('nc_talk_send_message', 'replace-file', 'known-or-included', 'compensating-only', 'talk'),
])

// NC13: Collectives tools slice
export const COLLECTIVES_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_collectives_read', 'read-content', 'known-or-included', 'not-applicable', 'collectives'),
	capability('nc_collectives_create_page', 'create-file', 'known-or-included', 'not-integrated', 'collectives'),
	capability('nc_collectives_delete_page', 'replace-file', 'known-or-included', 'not-integrated', 'collectives'),
])

// NC13: Forms tools slice
export const FORMS_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_forms_read', 'read-content', 'known-or-included', 'not-applicable', 'forms'),
	capability('nc_forms_create_form', 'create-file', 'known-or-included', 'not-integrated', 'forms'),
	capability('nc_forms_submit_response', 'replace-file', 'known-or-included', 'compensating-only', 'forms'),
])

// NC14: Whiteboard tools slice (stored board files only; live canvas held)
export const WHITEBOARD_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_whiteboard_read', 'read-content', 'known-or-included', 'not-applicable', 'whiteboard'),
	capability('nc_whiteboard_create', 'create-file', 'known-or-included', 'not-integrated', 'whiteboard'),
])

// NC16: Native Flow tools slice
export const FLOW_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_flow_read', 'read-content', 'known-or-included', 'not-applicable', 'flow'),
	capability('nc_flow_create_draft', 'create-file', 'known-or-included', 'not-integrated', 'flow'),
	capability('nc_flow_update_rule', 'replace-file', 'known-or-included', 'not-integrated', 'flow'),
])

// NC15: Windmill tools slice
export const WINDMILL_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_windmill_read', 'read-content', 'known-or-included', 'not-applicable', 'windmill'),
	capability('nc_windmill_draft', 'create-file', 'known-or-included', 'not-integrated', 'windmill'),
	capability('nc_windmill_run', 'replace-file', 'known-or-included', 'compensating-only', 'windmill'),
])

// NC15: n8n tools slice
export const N8N_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_n8n_read', 'read-content', 'known-or-included', 'not-applicable', 'n8n'),
	capability('nc_n8n_draft', 'create-file', 'known-or-included', 'not-integrated', 'n8n'),
	capability('nc_n8n_execute', 'replace-file', 'known-or-included', 'compensating-only', 'n8n'),
])

// NC20: Agent definition files slice
export const AGENTFILE_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_agentfile_read', 'read-content', 'known-or-included', 'not-applicable', 'agentfile'),
])

// NC16: Notes tools slice
export const NOTES_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_notes_read', 'read-content', 'known-or-included', 'not-applicable', 'notes'),
	capability('nc_notes_create', 'create-file', 'known-or-included', 'not-integrated', 'notes'),
])

// NC16: Bookmarks tools slice
export const BOOKMARKS_CAPABILITIES: readonly FileToolCapability[] = Object.freeze([
	capability('nc_bookmarks_read', 'read-content', 'known-or-included', 'not-applicable', 'bookmarks'),
	capability('nc_bookmarks_create', 'create-file', 'known-or-included', 'not-integrated', 'bookmarks'),
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
		...MAIL_CAPABILITIES,
		...TALK_CAPABILITIES,
		...COLLECTIVES_CAPABILITIES,
		...FORMS_CAPABILITIES,
		...WHITEBOARD_CAPABILITIES,
		...FLOW_CAPABILITIES,
		...WINDMILL_CAPABILITIES,
		...N8N_CAPABILITIES,
		...AGENTFILE_CAPABILITIES,
		...NOTES_CAPABILITIES,
		...BOOKMARKS_CAPABILITIES,
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
	// Interfaces and mock tests do not establish production provider support.
	experimentalSubsystems: Object.freeze([
		'files', 'tables', 'deck', 'calendar', 'tasks', 'contacts',
		'mail', 'talk', 'collectives', 'forms', 'whiteboard',
		'flow', 'windmill', 'n8n', 'agentfile', 'notes', 'bookmarks',
	] as const),
	expandedCatalog: 'not-enabled' as const,
	dispositionSource: 'fadenstack-dev/nextcloud-agent-implementation/tool-rollout.csv',
})
