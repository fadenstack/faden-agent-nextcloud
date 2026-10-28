import type { Passage } from './passages.ts'
import type { ScopePolicy, NativeResource, ResourcePurpose } from '../../platform/scope.ts'
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
	extendedFilesEnabled?: boolean
	tablesEnabled?: boolean
	deckEnabled?: boolean
	calendarEnabled?: boolean
	tasksEnabled?: boolean
	contactsEnabled?: boolean
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
export interface WriteResult { operation: string; file: FileMetadata; actionId?: string }
export type HistoryDirection = 'undo' | 'redo' | 'reconcile' | 'revalidate'
export interface HistoryItem { id: string; name: string; target?: string; state: string; eligible: HistoryDirection | 'none'; version: number; createdAt: number }
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
export interface SearchCapabilities { filename_search: boolean; document_search: boolean; indexed_content_search: boolean; provider: string | null; explanation: string }
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
	search_mode: 'filenames' | 'indexed-content' | 'document'
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
}

export interface FolderListingEntry {
	href: string
	filename: string
	etag: string
	folder: boolean
}

export interface FolderListing {
	directory: string
	entries: FolderListingEntry[]
}

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

export interface FileApi {
	history?: HistoryApi
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
	createFolder?(folderPath: string, name: string, signal?: AbortSignal): Promise<FolderCreateResult>
	copyFile?(sourcePath: string, destinationPath: string, expectedEtag: string, signal?: AbortSignal): Promise<FileCopyMoveResult>
	moveFile?(sourcePath: string, destinationPath: string, expectedEtag: string, signal?: AbortSignal): Promise<FileCopyMoveResult>
	sharesRead?(path: string, signal?: AbortSignal): Promise<ShareEntry[]>
	shareReadonly?(path: string, recipientUserId: string, expectedEtag: string, signal?: AbortSignal): Promise<ShareEntry>
	organizeRead?(folderPath?: string, signal?: AbortSignal): Promise<FolderListing>
	getAppCapabilities?(signal?: AbortSignal): Promise<AppCapabilities>
}
