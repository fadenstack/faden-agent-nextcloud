import type { DestinationAudience, SourceClass } from '../platform/scope.ts'
import type { ProviderCapability } from './registry.ts'

export interface CalendarCollection {
	readonly provider: 'calendar'
	readonly resourceId: string
	readonly collectionId: string
	readonly revision: string
	readonly sourceClass: SourceClass
	readonly audience: DestinationAudience
	readonly policyVersion: string
	readonly title: string
}

export interface CalendarEvent extends CalendarCollection {
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
	readonly status: 'saved' | 'deleted'
	readonly outcome: 'verified'
	readonly etag?: string
	readonly target: string
	readonly collectionId?: string
	readonly filename?: string
	readonly deleted?: boolean
	readonly errorMessage?: string
}

export interface NativeProvidersApi {
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

	contactsBooks?(signal: AbortSignal): Promise<readonly unknown[]>
	contactsFind?(query: string, signal: AbortSignal): Promise<readonly unknown[]>
	contactsRead?(bookId: string, filename?: string, signal?: AbortSignal): Promise<unknown>
	contactsPropose?(operation: 'create' | 'patch' | 'delete', bookId: string, filename: string, vcardData: string, expectedEtag?: string | null, signal?: AbortSignal): Promise<CalendarProposalSummary>
	contactsApprove?(id: string, signal?: AbortSignal): Promise<{ readonly approved: boolean }>
	contactsCommit?(id: string, signal?: AbortSignal): Promise<CalendarCommitOutcome>
}

/** Only our fixed native endpoints; credentials/URLs never appear in tool arguments. */
export function nativeProvidersApi(post: <T>(path: string, body: unknown, signal: AbortSignal) => Promise<T>): NativeProvidersApi {
	return {
		capabilities: async signal => (await post<{ providers: ProviderCapability[] }>('/providers/capabilities', {}, signal)).providers,
		calendarCollections: async signal => (await post<{ collections: CalendarCollection[] }>('/providers/calendar/collections', {}, signal)).collections,
		calendarEvents: async (collectionId, signal) => (await post<{ events: CalendarEvent[] }>('/providers/calendar/events', { collectionId }, signal)).events,
		calendarEvent: async (collectionId, filename, signal) => post<{ etag: string; content: string }>('/providers/calendar/event', { collectionId, filename }, signal),
		calendarPropose: async (operation, collectionId, filename, icsData, expectedEtag, signal) => post<CalendarProposalSummary>('/providers/calendar/propose', { operation, collectionId, filename, icsData, expectedEtag }, signal ?? new AbortController().signal),
		calendarApprove: async (id, signal) => post<{ approved: boolean }>(`/providers/calendar/${encodeURIComponent(id)}/approve`, {}, signal ?? new AbortController().signal),
		calendarCommit: async (id, signal) => post<CalendarCommitOutcome>(`/providers/calendar/${encodeURIComponent(id)}/commit`, {}, signal ?? new AbortController().signal),
	}
}
