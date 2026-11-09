import type { ProviderCapability } from './registry.ts'
import type { ProviderResource } from './scope.ts'
import type { TableSnapshot, DeckBoardSnapshot } from '../integrations/nextcloud/types.ts'
import { changedEngineConnection, engineConnectionList, engineWebhookList, engineWorkspaceLinks, engineOAuthList, engineOAuthAuthorization, type EngineOAuthSummary, type EngineConnectionSummary, type EngineWebhookSummary, type EngineWorkspaceLink } from './engineConnections.ts'

export interface CommunicationRead extends Record<string, unknown> { resource: ProviderResource }
export type ContentProviderId = 'collectives' | 'forms' | 'notes' | 'bookmarks' | 'whiteboard' | 'flow' | 'windmill' | 'n8n'
export type ReadProviderId = ContentProviderId | 'agentfile'
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
export interface ContentProposal extends Omit<StructuredProposal, 'provider'> { provider: ContentProviderId; destinationAudience?: 'private' | 'unknown' }
export interface ContentOutcome extends Omit<CommunicationOutcome, 'provider' | 'outcome'> { provider: ContentProviderId; outcome: 'verified' | 'running' | 'partial' | 'unknown' }
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
	readonly operation?: string
	readonly provider: 'files' | 'calendar' | 'tasks' | 'contacts' | 'tables' | 'deck' | 'mail' | 'talk' | ContentProviderId
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
		| 'matches_saved_draft' | 'different_or_ambiguous' | 'draft_absent' | 'job_running' | 'job_succeeded' | 'job_failed' | 'job_cancelled' | 'job_unconfirmed'
	readonly journalState: string
	readonly outcome: 'verified_committed' | 'not_dispatched' | 'unknown'
	readonly repeatedDispatch: false
	readonly message: string
}

export interface NativeProvidersApi {
	engineOAuth?(signal: AbortSignal): Promise<readonly EngineOAuthSummary[]>
	engineOAuthStart?(target: EngineOAuthSummary, revision: string, signal: AbortSignal): Promise<string>
	engineOAuthRefresh?(targetId: string, connectionId: string, revision: string, signal: AbortSignal): Promise<EngineConnectionSummary>
	engineOAuthRemove?(targetId: string, connectionId: string, revision: string, signal: AbortSignal): Promise<EngineConnectionSummary>
	engineWorkspaces?(signal: AbortSignal): Promise<readonly EngineWorkspaceLink[]>
	/** Native human settings only; these methods are never exposed as agent tools. */
	engineConnections?(signal: AbortSignal): Promise<readonly EngineConnectionSummary[]>
	engineConnect?(id: string, revision: string, token: string, signal: AbortSignal): Promise<EngineConnectionSummary>
	engineDisconnect?(id: string, revision: string, signal: AbortSignal): Promise<EngineConnectionSummary>
	engineWebhooks?(signal: AbortSignal): Promise<readonly EngineWebhookSummary[]>
	engineWebhookChange?(targetId: string, connectionId: string, revision: string, secret: string | null, signal: AbortSignal): Promise<EngineConnectionSummary>
	contentRead?(provider: ReadProviderId, args: Readonly<Record<string, unknown>>, signal: AbortSignal): Promise<CommunicationRead>
	contentPropose?(toolName: string, args: Readonly<Record<string, unknown>>, signal: AbortSignal): Promise<ContentProposal>
	contentApprove?(proposal: ContentProposal, signal: AbortSignal): Promise<unknown>
	contentCommit?(proposal: ContentProposal, signal: AbortSignal, step: number): Promise<ContentOutcome>
	contentCancel?(proposal: ContentProposal): Promise<unknown>
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
export function nativeProvidersApi(post: <T>(path: string, body: unknown, signal: AbortSignal) => Promise<T>, authenticatedPost = post): NativeProvidersApi {
	const family = (provider: string) => provider === 'agentfile' ? 'agentfile' : ['windmill', 'n8n'].includes(provider) ? 'engines' : ['whiteboard', 'flow'].includes(provider) ? 'workflow' : 'content'
	return {
		engineOAuth: async signal => engineOAuthList(await post('/engines/oauth', {}, signal)),
		engineOAuthStart: async (target, revision, signal) => engineOAuthAuthorization(await post('/engines/oauth/start', { targetId: target.id, revision }, signal), target),
		engineOAuthRefresh: async (targetId, id, revision, signal) => changedEngineConnection(await post('/engines/oauth/refresh', { targetId, revision }, signal), id, revision, true),
		engineOAuthRemove: async (targetId, id, revision, signal) => changedEngineConnection(await post('/engines/oauth/remove', { id, targetId, revision }, signal), id, revision, true),
		engineWorkspaces: async signal => engineWorkspaceLinks(await post('/engines/workspaces', {}, signal)),
		engineConnections: async signal => engineConnectionList(await post('/engines/connections', {}, signal)),
		engineConnect: async (id, revision, token, signal) => changedEngineConnection(await post('/engines/connections/connect', { id, revision, token }, signal), id, revision, true),
		engineDisconnect: async (id, revision, signal) => changedEngineConnection(await post('/engines/connections/disconnect', { id, revision }, signal), id, revision, false),
		engineWebhooks: async signal => engineWebhookList(await post('/engines/webhooks', {}, signal)),
		engineWebhookChange: async (targetId, id, revision, secret, signal) => changedEngineConnection(await post('/engines/webhooks/change', { targetId, revision, secret }, signal), id, revision, true),
		contentRead: (provider, args, signal) => post(`/providers/${family(provider)}/read`, { provider, args }, signal),
		contentPropose: async (toolName, args, signal) => {
			const route = family(toolName.split('_')[1]!)
			const requestId = [...crypto.getRandomValues(new Uint8Array(24))].map(value => value.toString(16).padStart(2, '0')).join('')
			try {
				const value = await (route === 'engines' ? authenticatedPost : post)<ContentProposal>(`/providers/${route}/propose`, { toolName, args, requestId }, signal)
				if (value.id !== requestId || !/^[a-f0-9]{64}$/.test(value.digest) || value.toolName !== toolName || !['collectives', 'forms', 'notes', 'bookmarks', 'whiteboard', 'flow', 'windmill', 'n8n'].includes(value.provider) || value.provider !== toolName.split('_')[1]
					|| value.history !== 'audit-only' || !Array.isArray(value.display) || !value.resource || !value.payload || typeof value.target !== 'string' || typeof value.limitation !== 'string'
					|| (route === 'engines' && value.destinationAudience !== (['nc_windmill_run', 'nc_n8n_execute'].includes(toolName) ? 'unknown' : 'private'))) throw new Error('Invalid native content preview.')
				signal.throwIfAborted()
				return value
			} catch (error) { await post(`/providers/${route}/${requestId}/cancel`, {}, new AbortController().signal).catch(() => {}); throw error }
		},
		contentApprove: (p, signal) => post(`/providers/${family(p.provider)}/${p.id}/approve`, { digest: p.digest }, signal),
		contentCommit: (p, signal, step) => post(`/providers/${family(p.provider)}/${p.id}/commit`, { digest: p.digest, ...(family(p.provider) === 'content' ? { step } : {}) }, signal),
		contentCancel: p => post(`/providers/${family(p.provider)}/${p.id}/cancel`, {}, new AbortController().signal),
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
