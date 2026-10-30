import type { ProviderCapability } from './registry.ts'
import type { ProviderResource } from './scope.ts'

export interface CalendarCollection extends ProviderResource { readonly title: string }
export interface CalendarEvent extends ProviderResource {
	readonly filename: string
	readonly content: string
	readonly contentProfile: 'icalendar-xml-normalized'
}
export interface NativeProvidersApi {
	capabilities(signal: AbortSignal): Promise<readonly ProviderCapability[]>
	calendarCollections(signal: AbortSignal): Promise<readonly CalendarCollection[]>
	calendarEvents(collectionId: string, signal: AbortSignal): Promise<readonly CalendarEvent[]>
}

/** Only our fixed native endpoints; credentials/URLs never appear in tool arguments. */
export function nativeProvidersApi(post: <T>(path: string, body: unknown, signal: AbortSignal) => Promise<T>): NativeProvidersApi {
	return {
		capabilities: async signal => (await post<{ providers: ProviderCapability[] }>('/providers/capabilities', {}, signal)).providers,
		calendarCollections: async signal => (await post<{ collections: CalendarCollection[] }>('/providers/calendar/collections', {}, signal)).collections,
		calendarEvents: async (collectionId, signal) => (await post<{ events: CalendarEvent[] }>('/providers/calendar/events', { collectionId }, signal)).events,
	}
}
