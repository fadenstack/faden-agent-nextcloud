import { defineTool } from '@fadenstack/agents'
import {
	parseDavProperties,
	calendarTools,
	tasksTools,
	contactsTools,
	type AgentWithCalendar,
	type AgentWithTasks,
	type AgentWithContacts,
} from '../integrations/nextcloud/caldav.ts'
import { TurnBudget, ScopeViolation } from '../platform/limits.ts'
import type { PublishedTool, ScopePolicy } from '../platform/scope.ts'
import { ProviderRegistry } from './registry.ts'
import { ProviderTurnScope, type ProviderResource } from './scope.ts'
import type { NativeProvidersApi } from './client.ts'
import { strongEtagHeader } from '../integrations/nextcloud/adapterGuards.ts'

const IMPLEMENTATIONS = new ProviderRegistry([
	{ provider: 'collectives', apiVersions: ['collectives-4.7.1-session'], tools: ['nc_collectives_read', 'nc_collectives_create_page', 'nc_collectives_delete_page'] },
	{ provider: 'forms', apiVersions: ['forms-5.4.1-session'], tools: ['nc_forms_read', 'nc_forms_create_form', 'nc_forms_submit_response'] },
	{ provider: 'notes', apiVersions: ['notes-6.1.0-session'], tools: ['nc_notes_read', 'nc_notes_create'] },
	{ provider: 'bookmarks', apiVersions: ['bookmarks-17.0.0-session'], tools: ['nc_bookmarks_read', 'nc_bookmarks_create'] },
	{ provider: 'mail', apiVersions: ['mail-5.13-api'], tools: ['nc_mail_read', 'nc_mail_save_draft', 'nc_mail_send_draft'] },
	{ provider: 'talk', apiVersions: ['talk-25-v4-v1'], tools: ['nc_talk_read', 'nc_talk_send_message'] },
	{ provider: 'tables', apiVersions: ['tables-2.4-api1'], tools: ['nc_tables_read', 'nc_tables_add_column', 'nc_tables_add_row', 'nc_tables_update_row', 'nc_tables_delete_row'] },
	{ provider: 'deck', apiVersions: ['deck-1.19-api1'], tools: ['nc_deck_read', 'nc_deck_patch', 'nc_deck_create', 'nc_deck_move', 'nc_deck_assign', 'nc_deck_label'] },
	{
		provider: 'calendar',
		apiVersions: ['caldav-v1'],
		tools: ['nc_calendar_list', 'nc_calendar_read', 'nc_calendar_create', 'nc_calendar_patch', 'nc_calendar_delete'],
	},
	{
		provider: 'tasks',
		apiVersions: ['caldav-v1'],
		tools: ['nc_tasks_read', 'nc_tasks_create', 'nc_tasks_patch', 'nc_tasks_delete'],
	},
	{
		provider: 'contacts',
		apiVersions: ['carddav-v1'],
		tools: ['nc_contacts_list', 'nc_contacts_find', 'nc_contacts_read', 'nc_contacts_create', 'nc_contacts_patch', 'nc_contacts_delete'],
	},
])

/** Read-only vertical slice; no cross-provider mutation is enabled by this class. */
export class ProviderReadRuntime {

	readonly #sessions = new Map<string, Map<string, Readonly<ProviderResource>>>()
	#sources = new Map<string, Readonly<ProviderResource>>()
	#scope: ProviderTurnScope | undefined
	#policy: ScopePolicy | undefined
	#epoch = 0

	constructor(readonly api: NativeProvidersApi) {}

	get offeredTools(): readonly string[] { return this.#scope?.tools.map(tool => tool.name) ?? [] }
	get hasSources(): boolean { return this.#sources.size > 0 }

	assertStructuredWrite(provider: 'tables' | 'deck' | 'mail' | 'talk' | 'collectives' | 'forms' | 'notes' | 'bookmarks', collectionId: string): void {
		if ([...this.#sources.values()].some(source => source.provider !== provider || source.collectionId !== collectionId)) {
			throw new ScopeViolation('movement_forbidden', 'Cross-collection source movement needs a separately certified audience policy. Start a new conversation for this native collection.')
		}
	}

	async prepare(sessionId: string | undefined, published: readonly PublishedTool[], policy: ScopePolicy, budget: TurnBudget,
		assertIdentity: () => void, signal: AbortSignal): Promise<void> {
		this.#scope?.invalidate()
		const epoch = ++this.#epoch
		this.#scope = undefined
		this.#sources = new Map(sessionId ? this.#sessions.get(sessionId) : undefined)
		this.#policy = policy
		const capabilities = await this.api.capabilities(signal)
		signal.throwIfAborted()
		if (epoch !== this.#epoch) throw new ScopeViolation('scope_changed', 'The provider turn changed.')
		assertIdentity()
		const tools = IMPLEMENTATIONS.available(capabilities, published.filter(tool => policy.tools.some(name => name === tool.name)))
		this.#scope = new ProviderTurnScope(tools, budget, assertIdentity, async (resource, write, innerSignal) => {
			if (write) {
				const cap = tools.find(item => item.provider === resource.provider)?.capability
				if (!cap?.canWrite) throw new Error('Provider mutations are not enabled by this configuration.')
			}
			let values: readonly ProviderResource[] = []
			if (resource.provider === 'calendar') {
				values = resource.resourceId === collectionReference('calendar', resource.collectionId)
					? await this.api.calendarCollections(innerSignal)
					: await this.api.calendarEvents(resource.collectionId, innerSignal)
			} else if (resource.provider === 'tasks') {
				values = resource.resourceId === collectionReference('tasks', resource.collectionId)
					? (await this.api.tasksLists?.(innerSignal)) ?? []
					: (await this.api.tasksItems?.(resource.collectionId, innerSignal)) ?? []
			} else if (resource.provider === 'contacts') {
				if (resource.resourceId === collectionReference('contacts', resource.collectionId)) {
					values = (await this.api.contactsBooks?.(innerSignal)) ?? []
				} else {
					if (!resource.filename) throw new ScopeViolation('source_forbidden', 'The recorded contact reference is incomplete.')
					const current = await this.api.contactsRead?.(resource.collectionId, resource.filename, innerSignal)
					values = current && 'resourceId' in current ? [current] : []
				}
			} else if (resource.provider === 'mail' || resource.provider === 'talk') {
				const args = resource.provider === 'talk' ? { roomToken: resource.collectionId } : JSON.parse(resource.filename || '{}')
				const value = await this.api.communicationRead?.(resource.provider, args, innerSignal)
				values = value ? [value.resource] : []
			} else if (resource.provider === 'collectives' || resource.provider === 'forms' || resource.provider === 'notes' || resource.provider === 'bookmarks') {
				const value = await this.api.contentRead?.(resource.provider, JSON.parse(resource.filename || '{}'), innerSignal)
				values = value ? [value.resource] : []
			} else if (resource.provider === 'tables' || resource.provider === 'deck') {
				const value = await this.api.structuredRead?.(resource.provider, Number(resource.collectionId), innerSignal)
				values = value ? [value.resource] : []
			}
			const current = values.find(value => value.resourceId === resource.resourceId)
			if (!current) throw new ScopeViolation('source_forbidden', 'The provider source is no longer available.')
			return current
		})
		for (const source of this.#sources.values()) this.#scope.bind(source)
		await this.assertProcessing(signal)
	}

	begin(name: string): void {
		if (!this.#scope) throw new Error('The native provider turn is unavailable.')
		this.#scope.begin(name)
	}

	readGuard(): () => void {
		const scope = this.#scope
		const epoch = this.#epoch
		if (!scope || !this.#policy) throw new Error('The native provider turn is unavailable.')
		return () => {
			if (this.#scope !== scope || this.#epoch !== epoch) throw new ScopeViolation('scope_changed', 'The provider turn changed.')
			scope.assertIdentity()
			scope.budget.assertLive()
		}
	}

	recordSource(value: unknown): void {
		if (!value || typeof value !== 'object') throw new Error('Invalid native provider resource metadata.')
		// record/bind validates every field before retaining this boundary value.
		this.record(value as ProviderResource)
	}

	/** Proposal preimages stay in the local approval view; they are not model tool output. */
	inspectLocalSource(value: unknown): void {
		if (!value || typeof value !== 'object') throw new Error('Invalid native provider resource metadata.')
		this.record(value as ProviderResource, false)
	}

	private record(resource: ProviderResource, retain = true): void {
		if (!this.#scope || !this.#policy) throw new Error('The native provider turn is unavailable.')
		if (!['calendar', 'tasks', 'contacts', 'tables', 'deck', 'mail', 'talk', 'collectives', 'forms', 'notes', 'bookmarks'].includes(resource.provider) || resource.sourceClass === 'unknown' || !this.#policy.sourceClasses.includes(resource.sourceClass)
			|| (resource.sourceClass === 'protected' && this.#policy.downstreamPolicy !== 'configured')) throw new ScopeViolation('processing_forbidden', 'This provider source cannot be sent to the configured model.')
		const previous = this.#sources.get(resource.resourceId)
		if (previous && previous.sourceClass !== resource.sourceClass) throw new ScopeViolation('processing_forbidden', 'Earlier source classification changed. Start a new conversation.')
		if (!previous && this.#sources.size >= 200) throw new ScopeViolation('resource_limit', 'Start a new conversation to use more provider sources.')
		if (resource.provider === 'contacts' && resource.resourceId !== collectionReference('contacts', resource.collectionId)
			&& (typeof resource.filename !== 'string' || !resource.filename || /[\x00-\x1f\x7f/\\]/.test(resource.filename)
				|| resource.resourceId !== collectionReference('contacts', resource.collectionId + '\0' + resource.filename))) throw new ScopeViolation('source_forbidden', 'The native contact reference is invalid.')
		if (resource.provider === 'contacts' && resource.filename
			&& (!('content' in resource) || typeof resource.content !== 'string' || resource.content.length > 65536
				|| parseDavProperties(resource.content, 'VCARD').instances !== 1)) throw new Error('Invalid native contact content.')
		this.#scope.bind(resource)
		if (!retain) return
		// Keep authorization metadata only; provider bodies already belong to the SDK conversation.
		this.#sources.set(resource.resourceId, Object.freeze({
			provider: resource.provider,
			resourceId: resource.resourceId,
			collectionId: resource.collectionId,
			revision: resource.revision,
			sourceClass: resource.sourceClass,
			audience: resource.audience,
			policyVersion: resource.policyVersion,
			...(resource.filename ? { filename: resource.filename } : {}),
		}))
	}

	/** Before every model request, including replay of earlier tool output. */
	async assertProcessing(signal: AbortSignal): Promise<void> {
		if (!this.#scope && this.hasSources) throw new ScopeViolation('source_forbidden', 'Earlier provider sources require current native authorization.')
		for (const source of this.#sources.values()) {
			const current = await this.#scope!.authorize(source.resourceId, source.provider, false, signal)
			this.record(current)
		}
	}

	/** Rebase only a structured collection revision independently verified by our native receipt. */
	async committedStructured(resource: ProviderResource, signal: AbortSignal): Promise<boolean> {
		if (!this.#scope || !['tables', 'deck'].includes(resource.provider)) return false
		const previous = this.#sources.get(resource.resourceId)
		if (!previous) return true
		try {
			const current = await this.#scope.resolve(previous, false, signal)
			if (current.provider !== previous.provider || current.collectionId !== previous.collectionId || current.resourceId !== previous.resourceId
				|| current.policyVersion !== previous.policyVersion || current.sourceClass !== previous.sourceClass || current.audience !== previous.audience || current.revision !== resource.revision) return false
			this.record(current)
			return true
		} catch { return false }
	}

	/** Rebase only our independently verified effect; arbitrary editor changes still fail authorize(). */
	async committed(provider: 'calendar' | 'tasks' | 'contacts', collectionId: string, filename: string,
		deleted: boolean, etag: string | undefined, signal: AbortSignal): Promise<boolean> {
		const guard = this.readGuard()
		const scope = this.#scope!
		const object = collectionReference(provider, collectionId + '\0' + filename)
		const collection = collectionReference(provider, collectionId)
		const affected = [...this.#sources.values()].filter(source => source.provider === provider && source.collectionId === collectionId
			&& [object, collection].includes(source.resourceId))
		// A deleted source cannot be freshly authorized. Keep its ledger and finish locally;
		// a new conversation is required before any of its old content could be replayed.
		if (deleted && affected.some(source => source.resourceId === object)) return false
		try {
			const verified: ProviderResource[] = []
			for (const previous of affected) {
				signal.throwIfAborted()
				const current = await scope.resolve(previous, false, signal)
				guard()
				signal.throwIfAborted()
				if (current.provider !== previous.provider || current.resourceId !== previous.resourceId || current.collectionId !== previous.collectionId
					|| current.policyVersion !== previous.policyVersion || current.sourceClass !== previous.sourceClass || current.audience !== previous.audience) return false
				if (previous.resourceId === object && (!etag || strongEtagHeader(current.revision) !== strongEtagHeader(etag))) return false
				verified.push(current)
			}
			for (const current of verified) this.record(current)
			return true
		} catch { return false }
	}

	async collections(signal: AbortSignal): Promise<unknown> {
		const scope = this.#scope
		if (!scope) throw new Error('The native provider turn is unavailable.')
		const discoveryOnly = scope.tools.find(tool => tool.name === 'nc_calendar_list')?.capability.discoveryOnly === true
		const values = discoveryOnly ? [] : await this.api.calendarCollections(signal)
		const tasks = scope.tools.some(tool => tool.provider === 'tasks') && this.api.tasksLists ? await this.api.tasksLists(signal) : []
		signal.throwIfAborted()
		if (!scope || this.#scope !== scope) throw new ScopeViolation('scope_changed', 'The provider turn changed.')
		if (!Array.isArray(values) || values.length > 8 || !Array.isArray(tasks) || tasks.length > 8) throw new Error('Invalid native Calendar collection result.')
		for (const resource of [...values, ...tasks]) {
			if (typeof resource.title !== 'string' || resource.title.length > 2048
				|| !['calendar', 'tasks'].includes(resource.provider)
				|| resource.resourceId !== collectionReference(resource.provider as 'calendar' | 'tasks', resource.collectionId)) throw new Error('Invalid native Calendar collection result.')
			this.record(resource)
		}
		return { provider: 'calendar', collections: [...values, ...tasks], scope: 'administrator-configured calendars and task lists', writesEnabled: scope.tools.some(tool => ['calendar', 'tasks'].includes(tool.provider) && tool.risk !== 'read') }
	}

	async events(reference: unknown, signal: AbortSignal): Promise<unknown> {
		if (!this.#scope) throw new Error('The native provider turn is unavailable.')
		const scope = this.#scope
		const collection = await scope.authorize(reference, 'calendar', false, signal)
		if (collection.resourceId !== collectionReference('calendar', collection.collectionId)) throw new Error('Choose a calendar collection returned by nc_calendar_list.')
		const values = await this.api.calendarEvents(collection.collectionId, signal)
		signal.throwIfAborted()
		if (this.#scope !== scope) throw new ScopeViolation('scope_changed', 'The provider turn changed.')
		if (!Array.isArray(values) || values.length > 50) throw new Error('Narrow the Calendar request.')
		const projected = values.map(resource => {
			if (resource.collectionId !== collection.collectionId || resource.policyVersion !== collection.policyVersion
				|| resource.contentProfile !== 'icalendar-xml-normalized' || typeof resource.content !== 'string' || resource.content.length > 65536) throw new Error('Invalid native Calendar event result.')
			const parsed = parseDavProperties(resource.content, 'VEVENT')
			if (parsed.instances < 1) throw new Error('The native event could not be parsed.')
			this.record(resource)
			return {
				resource_id: resource.resourceId,
				revision: resource.revision,
				collection_id: resource.collectionId,
				filename: resource.filename,
				properties: parsed.values,
				sourceClass: resource.sourceClass,
				audience: resource.audience,
			}
		})
		return { provider: 'calendar', events: projected, complete: true, limit: 50 }
	}

	finish(sessionId: string | undefined): void {
		this.#epoch++
		if (sessionId) this.#sessions.set(sessionId, new Map(this.#sources))
		this.#scope?.invalidate()
		this.#scope = undefined
	}

	dispose(): void { this.#epoch++; this.#scope?.invalidate(); this.#scope = undefined; this.#sources.clear(); this.#sessions.clear() }

}

function collectionReference(provider: 'calendar' | 'tasks' | 'contacts', id: string): string {
	const bytes = new TextEncoder().encode(id)
	return 'nextcloud:' + provider + ':' + btoa(String.fromCharCode(...bytes)).replaceAll('+', '-').replaceAll('/', '_').replace(/=+$/, '')
}

export function nativeCalendarReadTools(runtime: ProviderReadRuntime, agent?: AgentWithCalendar) {
	const tools = [
		defineTool({
			name: 'nc_calendar_list',
			toolClass: 'read',
			description: 'List authorized CalDAV collections. provider=calendar collections support nc_calendar_read; provider=tasks collections provide collectionId for the Tasks tools. References never grant access.',
			parameters: { type: 'object', properties: {}, additionalProperties: false },
			run: async (args, call) => {
				if (Object.keys(args).length) throw new Error('Calendar discovery accepts no arguments.')
				return runtime.collections(call.signal)
			},
		}),
		defineTool({
			name: 'nc_calendar_read',
			toolClass: 'read',
			description: 'Read up to 50 events from an authorized calendar returned by nc_calendar_list. Retain the returned collection_id, filename and exact revision for individually approved edits.',
			parameters: { type: 'object', properties: { resource_id: { type: 'string', description: 'Canonical collection resourceId from nc_calendar_list.' } }, required: ['resource_id'], additionalProperties: false },
			run: async (args, call) => {
				if (Object.keys(args).some(key => key !== 'resource_id')) throw new Error('Use only a canonical Calendar resource_id.')
				return runtime.events(args.resource_id, call.signal)
			},
		}),
	]
	if (agent) tools.push(...calendarTools(agent).slice(2))
	return tools
}

export function nativeTasksTools(runtime: ProviderReadRuntime, agent?: AgentWithTasks) {
	if (!agent) return []
	return tasksTools(agent)
}

export function nativeContactsTools(runtime: ProviderReadRuntime, agent?: AgentWithContacts) {
	if (!agent) return []
	return contactsTools(agent)
}
