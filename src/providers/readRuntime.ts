import { defineTool } from '@fadenstack/agents'
import { parseDavProperties } from '../integrations/nextcloud/caldav.ts'
import { TurnBudget, ScopeViolation } from '../platform/limits.ts'
import type { PublishedTool, ScopePolicy } from '../platform/scope.ts'
import { ProviderRegistry } from './registry.ts'
import { ProviderTurnScope, type ProviderResource } from './scope.ts'
import type { NativeProvidersApi } from './client.ts'

const IMPLEMENTATIONS = new ProviderRegistry([{
	provider: 'calendar',
	apiVersions: ['caldav-v1'],
	tools: ['nc_calendar_list', 'nc_calendar_read'],
}])

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
		const tools = IMPLEMENTATIONS.available(capabilities, published)
		this.#scope = new ProviderTurnScope(tools, budget, assertIdentity, async (resource, write, innerSignal) => {
			if (write) throw new Error('Provider mutations are not enabled by this read-only slice.')
			const values = resource.resourceId === collectionReference(resource.collectionId)
				? await this.api.calendarCollections(innerSignal)
				: await this.api.calendarEvents(resource.collectionId, innerSignal)
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

	private record(resource: ProviderResource): void {
		if (!this.#scope || !this.#policy) throw new Error('The native provider turn is unavailable.')
		if (resource.provider !== 'calendar' || resource.sourceClass === 'unknown' || !this.#policy.sourceClasses.includes(resource.sourceClass)
			|| (resource.sourceClass === 'protected' && this.#policy.downstreamPolicy !== 'configured')) throw new ScopeViolation('processing_forbidden', 'This provider source cannot be sent to the configured model.')
		const previous = this.#sources.get(resource.resourceId)
		if (previous && previous.sourceClass !== resource.sourceClass) throw new ScopeViolation('processing_forbidden', 'Earlier source classification changed. Start a new conversation.')
		if (!previous && this.#sources.size >= 200) throw new ScopeViolation('resource_limit', 'Start a new conversation to use more provider sources.')
		this.#scope.bind(resource)
		// Keep authorization metadata only; calendar bodies already belong to the SDK conversation.
		this.#sources.set(resource.resourceId, Object.freeze({
			provider: resource.provider,
			resourceId: resource.resourceId,
			collectionId: resource.collectionId,
			revision: resource.revision,
			sourceClass: resource.sourceClass,
			audience: resource.audience,
			policyVersion: resource.policyVersion,
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

	async collections(signal: AbortSignal): Promise<unknown> {
		const scope = this.#scope
		const values = await this.api.calendarCollections(signal)
		signal.throwIfAborted()
		if (!scope || this.#scope !== scope) throw new ScopeViolation('scope_changed', 'The provider turn changed.')
		if (!Array.isArray(values) || values.length > 8) throw new Error('Invalid native Calendar collection result.')
		for (const resource of values) {
			if (typeof resource.title !== 'string' || resource.title.length > 2048
				|| resource.resourceId !== collectionReference(resource.collectionId)) throw new Error('Invalid native Calendar collection result.')
			this.record(resource)
		}
		return { provider: 'calendar', collections: values, scope: 'administrator-configured collections', writesEnabled: false }
	}

	async events(reference: unknown, signal: AbortSignal): Promise<unknown> {
		if (!this.#scope) throw new Error('The native provider turn is unavailable.')
		const scope = this.#scope
		const collection = await scope.authorize(reference, 'calendar', false, signal)
		if (collection.resourceId !== collectionReference(collection.collectionId)) throw new Error('Choose a calendar collection returned by nc_calendar_list.')
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

function collectionReference(id: string): string {
	const bytes = new TextEncoder().encode(id)
	return 'nextcloud:calendar:' + btoa(String.fromCharCode(...bytes)).replaceAll('+', '-').replaceAll('/', '_').replace(/=+$/, '')
}

export function nativeCalendarReadTools(runtime: ProviderReadRuntime) {
	return [
		defineTool({
			name: 'nc_calendar_list',
			toolClass: 'read',
			description: 'List the native calendar collections allowed by the administrator and current user permissions.',
			parameters: { type: 'object', properties: {}, additionalProperties: false },
			run: async (args, call) => {
				if (Object.keys(args).length) throw new Error('Calendar discovery accepts no arguments.')
				return runtime.collections(call.signal)
			},
		}),
		defineTool({
			name: 'nc_calendar_read',
			toolClass: 'read',
			description: 'Read up to 50 events from an authorized calendar returned by nc_calendar_list. This development slice does not change calendars.',
			parameters: { type: 'object', properties: { resource_id: { type: 'string', description: 'Canonical collection resourceId from nc_calendar_list.' } }, required: ['resource_id'], additionalProperties: false },
			run: async (args, call) => {
				if (Object.keys(args).some(key => key !== 'resource_id')) throw new Error('Use only a canonical Calendar resource_id.')
				return runtime.events(args.resource_id, call.signal)
			},
		}),
	]
}
