import type { ProviderCapability } from './registry.ts'
import type { ProviderResource } from './scope.ts'
import type { TableSnapshot, DeckBoardSnapshot } from '../integrations/nextcloud/types.ts'

export interface CommunicationRead extends Record<string, unknown> { resource: ProviderResource }
export interface CommunicationOutcome {
	readonly outcome: 'verified' | 'running' | 'partial'
	readonly continue: boolean
	readonly actionId: string
	readonly provider: 'mail' | 'talk'
	readonly target: string
	readonly collectionId?: string
	readonly mailboxId?: number
	readonly nativeId?: number
	readonly step?: string
	readonly result?: Readonly<Record<string, unknown>>
	readonly history: 'audit-only'
}

export interface StructuredProposal {
	readonly id: string
	readonly digest: string
	readonly toolName: string
	readonly provider: 'tables' | 'deck'
	readonly target: string
	readonly before: unknown
	readonly display?: readonly { readonly label: string; readonly before: unknown; readonly after: unknown }[]
	readonly payload: Readonly<Record<string, unknown>>
	readonly resource: ProviderResource
	readonly limitation: string
	readonly history: 'audit-only'
	readonly expires: number
}
export interface StructuredRead {
	readonly snapshot: TableSnapshot | DeckBoardSnapshot
	readonly resource: ProviderResource
}
export interface CommunicationProposal extends Omit<StructuredProposal, 'provider'> { provider: 'mail' | 'talk' }
export interface StructuredOutcome {
	readonly status: 'saved' | 'deleted'
	readonly outcome: 'verified'
	readonly actionId: string
	readonly target: string
	readonly provider: 'tables' | 'deck'
	readonly collectionId: number
	readonly result: { readonly id: number; readonly stackId?: number; readonly deleted?: boolean }
	readonly resource: ProviderResource
	readonly history: 'audit-only'
	readonly limitation: string
}

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
	readonly provider: 'files' | 'calendar' | 'tasks' | 'contacts' | 'tables' | 'deck' | 'mail' | 'talk'
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
	readonly observed: 'present' | 'absent' | 'matches_saved_content' | 'different_content' | 'not_in_active_collection' | 'recorded-attempt'
	readonly journalState: string
	readonly outcome: 'verified_committed' | 'unknown'
	readonly repeatedDispatch: false
	readonly message: string
}

export interface NativeProvidersApi {
	communicationRead?(provider: 'mail' | 'talk', args: Readonly<Record<string, unknown>>, signal: AbortSignal): Promise<CommunicationRead>
	communicationPropose?(toolName: string, args: Readonly<Record<string, unknown>>, signal: AbortSignal): Promise<CommunicationProposal>
	communicationApprove?(proposal: CommunicationProposal, signal: AbortSignal): Promise<unknown>
	communicationCommit?(proposal: CommunicationProposal, signal: AbortSignal, step: number): Promise<CommunicationOutcome>
	communicationCancel?(proposal: CommunicationProposal): Promise<unknown>
	structuredRead?(provider: 'tables' | 'deck', collectionId: number, signal: AbortSignal): Promise<StructuredRead>
	structuredPropose?(toolName: string, args: Readonly<Record<string, unknown>>, signal: AbortSignal): Promise<StructuredProposal>
	structuredApprove?(proposal: StructuredProposal, signal: AbortSignal): Promise<{ readonly approved: boolean }>
	structuredCommit?(proposal: StructuredProposal, signal: AbortSignal): Promise<StructuredOutcome>
	structuredCancel?(proposal: StructuredProposal): Promise<unknown>
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
		communicationRead: (provider, args, signal) => post('/providers/communications/read', { provider, args }, signal),
		communicationPropose: async (toolName, args, signal) => {
			const requestId = [...crypto.getRandomValues(new Uint8Array(24))].map(value => value.toString(16).padStart(2, '0')).join('')
			try {
				const value = await post<CommunicationProposal>('/providers/communications/propose', { toolName, args, requestId }, signal)
				if (value.id !== requestId || !/^[a-f0-9]{64}$/.test(value.digest) || value.toolName !== toolName || !['mail', 'talk'].includes(value.provider)
					|| value.history !== 'audit-only' || !Array.isArray(value.display) || !value.resource || !value.payload) throw new Error('Invalid native communications preview.')
				signal.throwIfAborted()
				return value
			} catch (error) { await post(`/providers/communications/${requestId}/cancel`, {}, new AbortController().signal).catch(() => {}); throw error }
		},
		communicationApprove: (p, signal) => post(`/providers/communications/${p.id}/approve`, { digest: p.digest }, signal),
		communicationCommit: (p, signal, step) => post(`/providers/communications/${p.id}/commit`, { digest: p.digest, step }, signal),
		communicationCancel: p => post(`/providers/communications/${p.id}/cancel`, {}, new AbortController().signal),
		structuredRead: async (provider, collectionId, signal) => post<StructuredRead>('/providers/structured/read', { provider, collectionId }, signal),
		structuredPropose: async (toolName, args, signal) => {
			const requestId = [...crypto.getRandomValues(new Uint8Array(24))].map(value => value.toString(16).padStart(2, '0')).join('')
			let value: StructuredProposal
			try { value = await post<StructuredProposal>('/providers/structured/propose', { toolName, args, requestId }, signal) } catch (error) {
				await post(`/providers/structured/${requestId}/cancel`, {}, new AbortController().signal).catch(() => {}); throw error
			}
			if (!/^[a-f0-9]{48}$/.test(value.id) || !/^[a-f0-9]{64}$/.test(value.digest) || value.toolName !== toolName
				|| value.id !== requestId || !['tables', 'deck'].includes(value.provider) || value.history !== 'audit-only' || typeof value.target !== 'string'
				|| !value.payload || typeof value.payload !== 'object' || !value.resource || typeof value.limitation !== 'string') throw new Error('Invalid native structured preview.')
			if (signal.aborted) { await post(`/providers/structured/${value.id}/cancel`, { digest: value.digest }, new AbortController().signal).catch(() => {}); signal.throwIfAborted() }
			return value
		},
		structuredApprove: async (p, signal) => post(`/providers/structured/${p.id}/approve`, { digest: p.digest }, signal),
		structuredCommit: async (p, signal) => post(`/providers/structured/${p.id}/commit`, { digest: p.digest }, signal),
		structuredCancel: async p => post(`/providers/structured/${p.id}/cancel`, { digest: p.digest }, new AbortController().signal),
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
