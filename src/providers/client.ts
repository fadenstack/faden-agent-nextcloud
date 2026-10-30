import type { ProviderCapability } from './registry.ts'
import type { ProviderResource } from './scope.ts'

export interface CalendarCollection extends ProviderResource {
	readonly title: string
}

export interface CalendarEvent extends ProviderResource {
	readonly filename: string
	readonly contentProfile: 'icalendar-xml-normalized'
	readonly content: string
}

export interface CalendarProposalSummary {
	readonly id: string
	readonly operation: 'create' | 'patch' | 'delete'
	readonly toolName: string
	readonly target: string
	readonly collectionId: string
	readonly filename: string
	readonly hash: string
	readonly expires: number
}

export interface CalendarCommitOutcome {
	readonly actionId?: string
	readonly status: 'saved' | 'deleted'
	readonly outcome: 'verified'
	readonly etag?: string
	readonly target: string
	readonly collectionId?: string
	readonly filename?: string
	readonly deleted?: boolean
	readonly errorMessage?: string
}

export interface ContactSummary extends ProviderResource {
	readonly provider: 'contacts'
	readonly filename: string
	readonly content: string
	readonly title?: string
	readonly fn?: string
	readonly email?: string
}

export type ContactReadResult = (ContactSummary & { readonly etag: string })
	| { readonly contacts: readonly ContactSummary[]; readonly complete: boolean }

export interface ProviderAuditItem {
	readonly id: string
	readonly provider: 'calendar' | 'tasks' | 'contacts'
	readonly name: string
	readonly target: string
	readonly state: string
	readonly eligible: 'none'
	readonly auditOnly: true
	readonly version: number
	readonly createdAt: number
}

export interface ProviderInspection {
	readonly action: ProviderAuditItem
	readonly observed: 'present' | 'absent' | 'matches_saved_content' | 'different_content'
	readonly journalState: string
	readonly outcome: 'verified_committed' | 'unknown'
	readonly repeatedDispatch: false
	readonly message: string
}

export interface NativeProvidersApi {
	actions?(signal: AbortSignal): Promise<readonly ProviderAuditItem[]>
	inspectAction?(id: string, signal: AbortSignal): Promise<ProviderInspection>
	capabilities(signal: AbortSignal): Promise<readonly ProviderCapability[]>
	calendarCollections(signal: AbortSignal): Promise<readonly CalendarCollection[]>
	calendarEvents(collectionId: string, signal: AbortSignal): Promise<readonly CalendarEvent[]>
	calendarEvent(collectionId: string, filename: string, signal: AbortSignal): Promise<{ readonly etag: string; readonly content: string }>
	calendarPropose(operation: 'create' | 'patch' | 'delete', collectionId: string, filename: string, icsData: string, expectedEtag?: string | null, signal?: AbortSignal): Promise<CalendarProposalSummary>
	calendarApprove(id: string, signal?: AbortSignal): Promise<{ readonly approved: boolean }>
	calendarCommit(id: string, signal?: AbortSignal): Promise<CalendarCommitOutcome>

	tasksLists?(signal: AbortSignal): Promise<readonly CalendarCollection[]>
	tasksItems?(collectionId: string, signal: AbortSignal): Promise<readonly CalendarEvent[]>
	taskItem?(collectionId: string, filename: string, signal: AbortSignal): Promise<{ readonly etag: string; readonly content: string }>
	tasksPropose?(operation: 'create' | 'patch' | 'delete', collectionId: string, filename: string, icsData: string, expectedEtag?: string | null, signal?: AbortSignal): Promise<CalendarProposalSummary>
	tasksApprove?(id: string, signal?: AbortSignal): Promise<{ readonly approved: boolean }>
	tasksCommit?(id: string, signal?: AbortSignal): Promise<CalendarCommitOutcome>

	contactsBooks?(signal: AbortSignal): Promise<readonly CalendarCollection[]>
	contactsFind?(query: string, signal: AbortSignal): Promise<readonly ContactSummary[]>
	contactsRead?(bookId: string, filename?: string, signal?: AbortSignal): Promise<ContactReadResult>
	contactsPropose?(operation: 'create' | 'patch' | 'delete', bookId: string, filename: string, vcardData: string, expectedEtag?: string | null, signal?: AbortSignal): Promise<CalendarProposalSummary>
	contactsApprove?(id: string, signal?: AbortSignal): Promise<{ readonly approved: boolean }>
	contactsCommit?(id: string, signal?: AbortSignal): Promise<CalendarCommitOutcome>
}

/** Only our fixed native endpoints; credentials/URLs never appear in tool arguments. */
export function nativeProvidersApi(post: <T>(path: string, body: unknown, signal: AbortSignal) => Promise<T>): NativeProvidersApi {
	return {
		actions: async signal => post<ProviderAuditItem[]>('/providers/actions', {}, signal),
		inspectAction: async (id, signal) => {
			if (!/^[a-f0-9]{32}$/.test(id)) throw new Error('Invalid native action reference.')
			return post<ProviderInspection>(`/providers/actions/${id}/inspect`, {}, signal)
		},
		capabilities: async signal => (await post<{ providers: ProviderCapability[] }>('/providers/capabilities', {}, signal)).providers,
		calendarCollections: async signal => (await post<{ collections: CalendarCollection[] }>('/providers/calendar/collections', {}, signal)).collections,
		calendarEvents: async (collectionId, signal) => (await post<{ events: CalendarEvent[] }>('/providers/calendar/events', { collectionId }, signal)).events,
		calendarEvent: async (collectionId, filename, signal) => post<{ etag: string; content: string }>('/providers/calendar/event', { collectionId, filename }, signal),
		calendarPropose: async (operation, collectionId, filename, icsData, expectedEtag, signal) => post<CalendarProposalSummary>('/providers/calendar/propose', { operation, collectionId, filename, icsData, expectedEtag }, signal ?? new AbortController().signal),
		calendarApprove: async (id, signal) => post<{ approved: boolean }>(`/providers/calendar/${encodeURIComponent(id)}/approve`, {}, signal ?? new AbortController().signal),
		calendarCommit: async (id, signal) => post<CalendarCommitOutcome>(`/providers/calendar/${encodeURIComponent(id)}/commit`, {}, signal ?? new AbortController().signal),

		tasksLists: async signal => (await post<{ lists: CalendarCollection[] }>('/providers/tasks/lists', {}, signal ?? new AbortController().signal)).lists,
		tasksItems: async (collectionId, signal) => (await post<{ tasks: CalendarEvent[] }>('/providers/tasks/items', { collectionId }, signal ?? new AbortController().signal)).tasks,
		taskItem: async (collectionId, filename, signal) => post<{ etag: string; content: string }>('/providers/tasks/item', { collectionId, filename }, signal ?? new AbortController().signal),
		tasksPropose: async (operation, collectionId, filename, icsData, expectedEtag, signal) => post<CalendarProposalSummary>('/providers/tasks/propose', { operation, collectionId, filename, icsData, expectedEtag }, signal ?? new AbortController().signal),
		tasksApprove: async (id, signal) => post<{ approved: boolean }>(`/providers/tasks/${encodeURIComponent(id)}/approve`, {}, signal ?? new AbortController().signal),
		tasksCommit: async (id, signal) => post<CalendarCommitOutcome>(`/providers/tasks/${encodeURIComponent(id)}/commit`, {}, signal ?? new AbortController().signal),

		contactsBooks: async signal => (await post<{ books: CalendarCollection[] }>('/providers/contacts/books', {}, signal ?? new AbortController().signal)).books,
		contactsFind: async (query, signal) => (await post<{ contacts: ContactSummary[]; complete: boolean }>('/providers/contacts/find', { query }, signal ?? new AbortController().signal)).contacts,
		contactsRead: async (bookId, filename, signal) => post<ContactReadResult>('/providers/contacts/read', { bookId, filename }, signal ?? new AbortController().signal),
		contactsPropose: async (operation, bookId, filename, vcardData, expectedEtag, signal) => post<CalendarProposalSummary>('/providers/contacts/propose', { operation, bookId, filename, vcardData, expectedEtag }, signal ?? new AbortController().signal),
		contactsApprove: async (id, signal) => post<{ approved: boolean }>(`/providers/contacts/${encodeURIComponent(id)}/approve`, {}, signal ?? new AbortController().signal),
		contactsCommit: async (id, signal) => post<CalendarCommitOutcome>(`/providers/contacts/${encodeURIComponent(id)}/commit`, {}, signal ?? new AbortController().signal),
	}
}
