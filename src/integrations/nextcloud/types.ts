import type { Passage } from './passages.ts'
import type { ScopePolicy, NativeResource, ResourcePurpose } from '../../platform/scope.ts'
import type { NativeProvidersApi } from '../../providers/client.ts'
export interface AppConfig {
	enabled: boolean
	serverUrl: string
	agent: string
	development: boolean
}
export interface Identity extends AppConfig {
	userId: string
	binding: string
	locale?: string
	scopePolicy?: ScopePolicy | null
	historyEnabled?: boolean
	filesReadsEnabled?: boolean
	folderCreateEnabled?: boolean
	providerReadsEnabled?: boolean
	filesMutationsEnabled?: boolean
	/** Development fixtures only until native provider scope/history acceptance. */
	extendedFilesEnabled?: boolean
	tablesEnabled?: boolean
	deckEnabled?: boolean
	calendarEnabled?: boolean
	tasksEnabled?: boolean
	contactsEnabled?: boolean
	mailEnabled?: boolean
	talkEnabled?: boolean
	collectivesEnabled?: boolean
	formsEnabled?: boolean
	whiteboardEnabled?: boolean
	flowEnabled?: boolean
	windmillEnabled?: boolean
	n8nEnabled?: boolean
	agentfileEnabled?: boolean
	notesEnabled?: boolean
	bookmarksEnabled?: boolean
}
export interface FileMetadata {
	id: number
	name: string
	path: string
	mime: string
	size: number
	etag: string
	folder: boolean
	permissions: number
	url: string
	modified?: number
	created?: number
	agentScope?: NativeResource
}
export interface FileContext {
	id: string
	file: FileMetadata
	parentId: number
	expires: number
}
export interface ReadResult {
	file: FileMetadata
	text: string
	partial: boolean
	totalCharacters: number
	processedCharacters: number
}
export interface Listing { folder: FileMetadata; files: FileMetadata[]; partial: boolean }
export interface WriteArguments {
	operation: 'create' | 'update'
	filename?: string
	content: string
	expectedEtag?: string
}
export interface Proposal extends WriteArguments {
	id: string
	target: string
	before: string | null
	contextId: string
}
export interface WriteResult { operation: string; file: FileMetadata; actionId?: string; provider?: { app: 'files' | 'calendar' | 'tasks' | 'contacts' | 'tables' | 'deck' | 'mail' | 'spreed' | 'collectives' | 'forms' | 'notes' | 'bookmarks' | 'flow' | 'windmill' | 'n8n'; collectionId: string; filename: string; date?: string; mailboxId?: number } }

export interface FileOperationProposal {
	id: string; digest: string; operation: 'copy' | 'move' | 'share_readonly'; toolName: string; target: string
	sourceResourceId: string; sourcePath: string; destinationResourceId: string; destinationName: string
	sourceAudience: import('../../platform/scope.ts').DestinationAudience; destinationAudience: import('../../platform/scope.ts').DestinationAudience
	expectedEtag: string; recipientUserId: string; expires: number; history: 'audit-only'; limitation: string
}
export interface FileOperationOutcome {
	outcome: 'verified' | 'unknown' | 'not-dispatched'; status?: string; actionId?: string; history: 'audit-only'
	file?: FileMetadata; parentResourceId?: string; shareId?: number | null; message?: string; disclosure?: 'restricted'
}
export type HistoryDirection = 'undo' | 'redo' | 'reconcile' | 'revalidate'
export interface HistoryItem { id: string; name: string; target?: string; state: string; eligible: HistoryDirection | 'none'; version: number; createdAt: number; auditOnly?: boolean; provider?: 'files' | 'calendar' | 'tasks' | 'contacts' | 'tables' | 'deck' | 'mail' | 'talk' | 'collectives' | 'forms' | 'notes' | 'bookmarks' | 'whiteboard' | 'flow' | 'windmill' | 'n8n'; fileUrl?: string; operation?: string }
export interface FileOperationsApi {
	actions?(signal: AbortSignal): Promise<HistoryItem[]>
	inspect?(id: string, signal: AbortSignal): Promise<import('../../providers/client.ts').ProviderInspection>
	capabilities(signal: AbortSignal): Promise<{ tools: string[] }>
	propose(args: { operation: 'copy' | 'move' | 'share_readonly'; sourceResourceId: string; expectedEtag: string; destinationResourceId?: string; destinationName?: string; recipientUserId?: string }, signal: AbortSignal): Promise<FileOperationProposal>
	approve(proposal: FileOperationProposal, signal: AbortSignal): Promise<void>
	commit(proposal: FileOperationProposal, signal: AbortSignal): Promise<FileOperationOutcome>
	cancel(proposal: FileOperationProposal): Promise<void>
}
export interface HistoryPreview { id: string; actionId: string; direction: HistoryDirection; digest: string; target: string; before: string; content: string; expires: number; resolution?: { outcome: 'verified' | 'unchanged'; state: 'applied' | 'undone' | 'failed' }; revalidation?: { state: 'applied' | 'undone'; recordedRevision: string; currentRevision: string } }
export interface HistoryAvailability { unavailableCount: number; earlierScopeCount: number; earlierContentExpiresBy: number | null; writesPaused?: boolean }
export interface HistoryApi {
	availability?(signal: AbortSignal): Promise<HistoryAvailability>
	list(signal: AbortSignal): Promise<HistoryItem[]>
	prepare(actionId: string, direction: HistoryDirection, signal: AbortSignal): Promise<HistoryPreview>
	approve(preview: HistoryPreview, signal: AbortSignal): Promise<void>
	execute(preview: HistoryPreview, signal: AbortSignal): Promise<WriteResult & { state: string }>
	cancel(preview: HistoryPreview, signal: AbortSignal): Promise<void>
}
export interface DavCreatePlan { kind: 'dav-create'; receiptId: string; dav_path: string; parent_resource_id: string; parent_path: string; parent_etag: string; content: string }
export interface SearchFilters {
	query?: string
	scope?: string
	file_types?: string[]
	modified_after?: string
	modified_before?: string
	created_after?: string
	min_size?: number
	max_size?: number
	favorites_only?: boolean
	sort_by?: 'relevance' | 'modified' | 'name' | 'size'
	sort_direction?: 'asc' | 'desc'
	limit?: number
	cursor?: string
}
export interface SearchPlan { filters: Required<Pick<SearchFilters, 'query' | 'scope' | 'limit' | 'sort_by' | 'sort_direction'>> & SearchFilters; queries: string[]; backend: string; next_cursor?: string | null }
export interface SearchCapabilities { filename_search: boolean; document_search: boolean; indexed_content_search: boolean; bounded_content_search?: boolean; provider: string | null; explanation: string }
export interface NativeContentResults { available: boolean; provider: string | null; explanation: string; results: { file: FileMetadata; excerpt: string | null }[]; partial?: boolean; next_page?: number | null }
export interface SearchTransport {
	plan(filters: SearchFilters, signal: AbortSignal): Promise<SearchPlan>
	dav(xml: string, signal: AbortSignal): Promise<string>
	resolve(resourceIds: string[], scope: string, signal: AbortSignal, content?: boolean): Promise<FileMetadata[]>
	capabilities(signal: AbortSignal): Promise<SearchCapabilities>
	content(query: string, limit: number, page: number, signal: AbortSignal): Promise<NativeContentResults>
}
export interface FileResource {
	resource_id: string
	name: string
	path: string
	mime_type: string
	size: number
	modified_at: string | null
	etag: string
	web_url: string
	is_directory: boolean
	capabilities: string[]
	excerpt?: string | null
}
export interface SearchResults {
	kind: 'nextcloud-search-results'
	search_mode: 'filenames' | 'indexed-content' | 'bounded-content' | 'document'
	provider: string
	ordering: string
	results: FileResource[]
	partial: boolean
	duration_ms: number
	available?: boolean
	explanation?: string
	next_page?: number | null
	next_cursor?: string | null
}
export interface DocumentSearchResults {
	kind: 'nextcloud-document-search'
	search_mode: 'document'
	provider: string
	file: FileResource
	matches: Passage[]
	processed_match_count: number
	total_matches: number | null
	partial: boolean
	matches_truncated: boolean
	cache_hit: boolean
	retrieval_ms: number
	duration_ms: number
}
export type SearchViewResults = SearchResults | DocumentSearchResults

export interface ShareEntry {
	id: number
	shareType: number
	shareWith?: string
	shareWithDisplayName?: string
	permissions: number
	path: string
	fileTarget?: string
	expiration?: string | null
	mailSend?: boolean
	inherited?: boolean
}

export interface ShareListing {
	resourceId: string
	etag: string
	shares: ShareEntry[]
	complete: boolean
	coverage: string
}

export interface FolderListingEntry {
	resourceId?: string
	href: string
	filename: string
	etag: string
	folder: boolean
	size?: number
	modified?: number
	permissions?: number
}

export interface FolderListing {
	directory: string
	resourceId?: string
	etag?: string
	entries: FolderListingEntry[]
	partial?: boolean
}

export interface FolderOverviewResults {
	kind: 'nextcloud-folder-overview'
	directory: string
	resourceId?: string
	etag?: string
	entries: FolderListingEntry[]
	partial: boolean
}

export interface ShareOverviewResults {
	kind: 'nextcloud-share-overview'
	resourceId: string
	etag: string
	shares: ShareEntry[]
	complete: boolean
	coverage: string
}

export type ToolViewResults = SearchViewResults | FolderOverviewResults | ShareOverviewResults

export interface DavFolderCreatePlan {
	kind: 'dav-create-folder'
	receiptId: string
	dav_path: string
	parent_resource_id: string
	parent_path: string
	parent_etag: string
	name: string
	target: string
}

export interface FolderCreateProposal {
	id: string
	toolName: 'nc_files_create_folder'
	resourceId: string
	folderId: number
	name: string
	parentEtag: string
	expectedRevision: string
	target: string
	expires: number
	approved: boolean
	executionReady?: boolean
	identityScope?: string
	identitySession?: string
}

interface FolderCreateReceipt {
	history: 'not-integrated' | 'audit-only'
	actionId?: string
	name: string
	parentResourceId: string
	proposalId: string
}

export type FolderCreateOutcome = (FolderCreateReceipt & {
	outcome: 'not-dispatched' | 'ambiguous'
	target: string
	message: string
}) | (FolderCreateReceipt & {
	outcome: 'verified'
	resourceId: string
	parentPath: string
	folder: FileMetadata
	observedState: {
		id: number
		name: string
		path: string
		etag: string
		permissions: number
		is_directory: boolean
	}
})

export interface FolderCreateResult {
	path: string
	name: string
	file?: FileMetadata
}

export interface FileCopyMoveResult {
	operation: 'copy' | 'move'
	source: string
	destination: string
	etag?: string
}

export interface AppCapabilities {
	capabilities?: Record<string, unknown>
	files_sharing?: { api_enabled?: boolean }
	tables?: Record<string, unknown>
	deck?: { version?: string }
	dav?: Record<string, unknown>
	[key: string]: unknown
}

export interface TableColumn {
	id: number
	title: string
	type: string
	subtype: string
	mandatory?: boolean
	[key: string]: unknown
}

export interface TableRow {
	revision?: string
	id: number
	tableId: number
	data: { columnId: number; value: unknown }[]
	lastEditBy?: unknown
	lastEditAt?: unknown
	[key: string]: unknown
}

export interface TableSnapshot {
	nativeResource?: import('../../providers/scope.ts').ProviderResource
	table: { id: number; title: string }
	columns: TableColumn[]
	rows: TableRow[]
	schemaRevision: string
}

export interface TableReadResult {
	table: { id: number; title: string }
	schemaRevision: string
	columns: { id: number; title: string; type: string; subtype: string }[]
	rows: { id: number; revision: string; cells: Record<string, unknown> }[]
	instructions: string
}

export interface TablesApi {
	readTable(tableId?: number, signal?: AbortSignal): Promise<TableSnapshot>
	addColumn(tableId: number, title: string, type: 'text' | 'number', signal?: AbortSignal): Promise<unknown>
	addRow(tableId: number, cells: Record<string, unknown>, signal?: AbortSignal): Promise<unknown>
	updateRow(tableId: number, rowId: number, cells: Record<string, unknown>, signal?: AbortSignal): Promise<unknown>
	deleteRow(tableId: number, rowId: number, signal?: AbortSignal): Promise<unknown>
}

export interface DeckCard {
	revision?: string
	id: number
	title: string
	description: string
	type: string
	order: number
	owner: unknown
	stackId: number
	duedate: string | null
	startdate?: string | null
	color?: string | null
	archived: boolean
	done: string | null
	assignedUsers?: unknown[]
	labels?: { id: number; title: string; color: string }[]
	[key: string]: unknown
}

export interface DeckStack {
	id: number
	title: string
	cards?: DeckCard[]
	[key: string]: unknown
}

export interface DeckBoard {
	id: number
	title: string
	color: string
	labels: { id: number; title: string; color: string }[]
	acl?: unknown[]
	[key: string]: unknown
}

export interface DeckBoardSnapshot {
	nativeResource?: import('../../providers/scope.ts').ProviderResource
	id: number
	board: DeckBoard
	stacks: DeckStack[]
}

export interface DeckApi {
	readBoard(boardId?: number, signal?: AbortSignal): Promise<DeckBoardSnapshot>
	patchCard(boardId: number, stackId: number, cardId: number, cardData: Record<string, unknown>, signal?: AbortSignal): Promise<unknown>
	createCard(boardId: number, stackId: number, title: string, description?: string, duedate?: string | null, signal?: AbortSignal): Promise<unknown>
	moveCard(cardId: number, stackId: number, order?: number, signal?: AbortSignal): Promise<unknown>
	assignCard(boardId: number, stackId: number, cardId: number, userId: string, signal?: AbortSignal): Promise<unknown>
	labelCard(boardId: number, stackId: number, cardId: number, labelId: number, signal?: AbortSignal): Promise<unknown>
}

export interface DavCalendar {
	id: string
	title: string
	href: string
	components: string[]
	canWrite: boolean
	color?: string
}

/** Optional for existing host mocks; native DAV reads always supply completeness. */
export type DavCollection<T> = T[] & { readonly complete?: boolean }

export interface DavCalendarObject {
	href: string
	filename: string
	etag: string
	summary?: string
	description?: string
	location?: string
	startUtc?: string
	endUtc?: string
	startDate?: string
	endDate?: string
	timezone?: string
	startTimezone?: string
	endTimezone?: string
	dueTimezone?: string
	dueDate?: string
	dueUtc?: string
	status?: string
	percentComplete?: string
	priority?: string
	uid?: string
	recurring?: boolean
	hasAttendees?: boolean
	propertyParameters?: Record<string, string>
	[key: string]: unknown
}

export interface DavAddressBook {
	id: string
	title: string
	href: string
	canWrite?: boolean
}

export interface DavContact {
	href: string
	book: string
	filename?: string
	uid?: string
	name?: string
	email?: string
	tel?: string
	note?: string
	categories?: string
	etag?: string
	[key: string]: unknown
}

export interface CalendarApi {
	listCalendars(signal?: AbortSignal): Promise<DavCollection<DavCalendar>>
	readEvents(calendarId: string, signal?: AbortSignal): Promise<DavCollection<DavCalendarObject>>
	getEvent(calendarId: string, filename: string, signal?: AbortSignal): Promise<{ etag: string; raw: string; values: Record<string, string> }>
	createEvent(calendarId: string, filename: string, icsData: string, signal?: AbortSignal): Promise<{ etag?: string; href: string }>
	updateEvent(calendarId: string, filename: string, icsData: string, expectedEtag: string, signal?: AbortSignal): Promise<{ etag?: string; href: string }>
	deleteEvent(calendarId: string, filename: string, expectedEtag: string, signal?: AbortSignal): Promise<void>
}

export interface TasksApi {
	listTaskLists(signal?: AbortSignal): Promise<DavCollection<DavCalendar>>
	readTasks(calendarId: string, signal?: AbortSignal): Promise<DavCollection<DavCalendarObject>>
	getTask(calendarId: string, filename: string, signal?: AbortSignal): Promise<{ etag: string; raw: string; values: Record<string, string> }>
	createTask(calendarId: string, filename: string, icsData: string, signal?: AbortSignal): Promise<{ etag?: string; href: string }>
	updateTask(calendarId: string, filename: string, icsData: string, expectedEtag: string, signal?: AbortSignal): Promise<{ etag?: string; href: string }>
	deleteTask(calendarId: string, filename: string, expectedEtag: string, signal?: AbortSignal): Promise<void>
}

export interface ContactsApi {
	listAddressBooks(signal?: AbortSignal): Promise<DavCollection<DavAddressBook>>
	findContacts(query: string, signal?: AbortSignal): Promise<{ contacts: DavContact[]; complete: boolean }>
	readContact(bookId: string, filename?: string, signal?: AbortSignal): Promise<{ etag: string; raw: string; values: Record<string, string>; contacts?: DavContact[]; complete?: boolean }>
	createContact(bookId: string, filename: string, vcardData: string, signal?: AbortSignal): Promise<{ etag?: string; href: string }>
	updateContact(bookId: string, filename: string, vcardData: string, expectedEtag: string, signal?: AbortSignal): Promise<{ etag?: string; href: string }>
	deleteContact(bookId: string, filename: string, expectedEtag: string, signal?: AbortSignal): Promise<void>
}

export interface MailMessage {
	id: number
	subject: string
	from: string
	to: string[]
	date: string
	body?: string
	hasAttachments?: boolean
	unread?: boolean
}

export interface MailDraft {
	id?: number
	accountRef: string
	subject: string
	body: string
	recipients: string[]
	attachments?: string[]
}

export interface MailApi {
	readMail(query?: string, accountId?: string, signal?: AbortSignal): Promise<{ messages: MailMessage[]; total: number }>
	saveDraft(draft: MailDraft, signal?: AbortSignal): Promise<{ draftId: number; revision: string }>
	sendDraft(draftId: number, expectedRevision: string, signal?: AbortSignal): Promise<{ delivered: boolean; messageId: string }>
}

export interface TalkRoom {
	token: string
	name: string
	type: number
	participants: string[]
	unreadMessages?: number
}

export interface TalkMessage {
	id: number
	token: string
	actorId: string
	actorDisplayName: string
	message: string
	timestamp: number
}

export interface TalkApi {
	readContext(roomToken: string, signal?: AbortSignal): Promise<{ room: TalkRoom; history: TalkMessage[] }>
	sendMessage(roomToken: string, message: string, replyTo?: number, signal?: AbortSignal): Promise<{ messageId: number; timestamp: number }>
}

export interface Collective {
	id: number
	name: string
}

export interface CollectivePage {
	id: number
	collectiveId: number
	title: string
	content?: string
	parentId?: number | null
}

export interface CollectivesApi {
	readHierarchy(collectiveId?: number, signal?: AbortSignal): Promise<{ collectives: Collective[]; pages: CollectivePage[] }>
	createPage(collectiveId: number, title: string, parentId?: number | null, signal?: AbortSignal): Promise<{ pageId: number; title: string }>
	deletePage(pageId: number, signal?: AbortSignal): Promise<{ deleted: boolean; pageId: number }>
}

export interface FormQuestion {
	id: number
	text: string
	type: string
	mandatory: boolean
	options?: string[]
}

export interface FormDefinition {
	id: number
	title: string
	questions: FormQuestion[]
}

export interface FormsApi {
	readForm(formId?: number, signal?: AbortSignal): Promise<{ forms: FormDefinition[]; submissions?: unknown[] }>
	createForm(title: string, questions: FormQuestion[], signal?: AbortSignal): Promise<{ formId: number; title: string }>
	submitResponse(formId: number, answers: Record<string, unknown>, signal?: AbortSignal): Promise<{ submissionId: number; recorded: boolean }>
}

export interface WhiteboardApi {
	readBoard(pathOrId: string, signal?: AbortSignal): Promise<{ id: string; scene: unknown }>
	createBoard(path: string, name: string, signal?: AbortSignal): Promise<{ path: string; created: boolean }>
}

export interface FlowRule {
	id: number
	description: string
	operation: string
	active: boolean
}

export interface FlowApi {
	readRules(signal?: AbortSignal): Promise<{ rules: FlowRule[] }>
	createDraftRule(description: string, operation: string, config: Record<string, unknown>, signal?: AbortSignal): Promise<{ ruleId: number; active: false }>
	updateRule(ruleId: number, active?: boolean, deleteRule?: boolean, signal?: AbortSignal): Promise<{ ruleId: number; active: boolean; deleted: boolean }>
}

export interface WindmillApi {
	readFlows(path?: string, signal?: AbortSignal): Promise<{ flows: unknown[]; jobs: unknown[] }>
	createDraftFlow(path: string, summary: string, value: unknown, signal?: AbortSignal): Promise<{ path: string; draft: boolean }>
	runFlow(path: string, args: Record<string, unknown>, signal?: AbortSignal): Promise<{ jobId: string; status: string }>
}

export interface N8nApi {
	readWorkflows(signal?: AbortSignal): Promise<{ workflows: unknown[]; executions: unknown[] }>
	createDraftWorkflow(name: string, nodes: unknown[], connections: unknown, signal?: AbortSignal): Promise<{ id: string; active: false }>
	executeWorkflow(workflowId: string, data?: Record<string, unknown>, signal?: AbortSignal): Promise<{ executionId: string; status: string }>
}

export interface AgentfileApi {
	readManifest(contentOrPath: string, signal?: AbortSignal): Promise<{ name: string; version: string; description: string; instructions: string }>
}

export interface NotesApi {
	readNotes(category?: string, signal?: AbortSignal): Promise<{ notes: { id: number; title: string; category: string; content?: string }[] }>
	createNote(title: string, content: string, category?: string, signal?: AbortSignal): Promise<{ noteId: number; title: string }>
}

export interface BookmarksApi {
	searchBookmarks(query?: string, signal?: AbortSignal): Promise<{ bookmarks: { id: number; url: string; title: string; tags: string[] }[] }>
	createBookmark(url: string, title?: string, tags?: string[], signal?: AbortSignal): Promise<{ bookmarkId: number; url: string }>
}

export interface FileApi {
	readonly fileOperations?: FileOperationsApi
	readonly nativeProviders?: NativeProvidersApi
	history?: HistoryApi
	tables?: TablesApi
	deck?: DeckApi
	calendar?: CalendarApi
	tasks?: TasksApi
	contacts?: ContactsApi
	mail?: MailApi
	talk?: TalkApi
	collectives?: CollectivesApi
	forms?: FormsApi
	whiteboard?: WhiteboardApi
	flow?: FlowApi
	windmill?: WindmillApi
	n8n?: N8nApi
	agentfile?: AgentfileApi
	notes?: NotesApi
	bookmarks?: BookmarksApi
	setCloudIdentity?(provider?: (signal?: AbortSignal) => Promise<string>): void
	searchTransport?: SearchTransport
	identity(signal?: AbortSignal): Promise<Identity>
	assertIdentity(signal?: AbortSignal): Promise<void>
	select(fileId: number, signal?: AbortSignal): Promise<FileContext>
	read(contextId: string, signal?: AbortSignal): Promise<ReadResult>
	pdf(contextId: string, signal?: AbortSignal): Promise<Uint8Array>
	list(contextId: string, signal?: AbortSignal): Promise<Listing>
	propose(contextId: string, args: WriteArguments, signal?: AbortSignal): Promise<Proposal>
	approve(id: string, signal?: AbortSignal): Promise<void>
	commit(id: string, signal?: AbortSignal): Promise<WriteResult>
	scopeResource?(resourceId: string, purpose: ResourcePurpose, signal: AbortSignal): Promise<NativeResource>
	proposeFolderCreate?(resourceId: string, name: string, expectedRevision?: string, signal?: AbortSignal): Promise<FolderCreateProposal>
	approveFolderCreate?(id: string, signal?: AbortSignal): Promise<void>
	cancelFolderCreate?(id: string, signal?: AbortSignal): Promise<void>
	commitFolderCreate?(id: string, signal?: AbortSignal): Promise<FolderCreateOutcome>
	finishFolderCreate?(receiptId: string, createdFileId?: string, signal?: AbortSignal): Promise<FolderCreateOutcome>
	createFolder?(folderPath: string, name: string, signal?: AbortSignal): Promise<FolderCreateResult>
	copyFile?(sourcePath: string, destinationPath: string, expectedEtag: string, signal?: AbortSignal): Promise<FileCopyMoveResult>
	moveFile?(sourcePath: string, destinationPath: string, expectedEtag: string, signal?: AbortSignal): Promise<FileCopyMoveResult>
	sharesRead?(resourceId: string, expectedRevisionOrSignal?: string | AbortSignal, signal?: AbortSignal): Promise<ShareListing>
	shareReadonly?(path: string, recipientUserId: string, expectedEtag: string, signal?: AbortSignal): Promise<ShareEntry>
	organizeRead?(resourceId?: string, expectedRevisionOrSignal?: string | AbortSignal, signal?: AbortSignal): Promise<FolderListing>
	getAppCapabilities?(signal?: AbortSignal): Promise<AppCapabilities>
}
