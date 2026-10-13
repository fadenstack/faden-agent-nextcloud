export interface AppConfig {
	enabled: boolean
	serverUrl: string
	agent: string
	development: boolean
}
export interface Identity extends AppConfig { userId: string; binding: string }
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
export interface WriteResult { operation: string; file: FileMetadata }
export interface FileApi {
	identity(): Promise<Identity>
	assertIdentity(): Promise<void>
	select(fileId: number, signal?: AbortSignal): Promise<FileContext>
	read(contextId: string, signal?: AbortSignal): Promise<ReadResult>
	pdf(contextId: string, signal?: AbortSignal): Promise<Uint8Array>
	list(contextId: string, signal?: AbortSignal): Promise<Listing>
	propose(contextId: string, args: WriteArguments, signal?: AbortSignal): Promise<Proposal>
	approve(id: string, signal?: AbortSignal): Promise<void>
	commit(id: string, signal?: AbortSignal): Promise<WriteResult>
}
