import { fileCapability, stricterRisk, type FileToolName, type NextcloudToolName, type ToolRisk } from './catalog.ts'
import { ScopeViolation, TurnBudget, type TurnLimits } from './limits.ts'

export type SourceClass = 'standard' | 'protected' | 'unknown'
export type ResourcePurpose = 'metadata' | 'read' | 'write' | 'history' | 'shares'
export type DestinationAudience = 'private' | 'shared' | 'public' | 'unknown'
export type FileSourceScope = Readonly<{ kind: 'user-files' } | { kind: 'file' | 'folder'; resourceId: string }>

/** Values must come from native identity and SDK-verified identity/profile, never model arguments. */
export interface ScopeIdentity {
	readonly nextcloudOrigin: string
	readonly nextcloudUser: string
	readonly nextcloudBinding: string
	readonly fadenstackOrigin: string
	/** The published SDK currently exposes a sign-in device/session binding, not a verified subject. */
	readonly fadenstackPrincipal: Readonly<{ kind: 'subject' | 'session'; id: string }>
	readonly agent: string
	readonly agentVersion: number
	readonly schemaVersion: string
	readonly contractVersion: string
	readonly policyVersion: string
}

/** This restriction is supplied by our native configuration endpoint, not an agent definition file. */
export interface ScopePolicy {
	readonly version: string
	readonly sources: readonly FileSourceScope[]
	readonly destinations: readonly FileSourceScope[]
	readonly destinationAudiences: readonly DestinationAudience[]
	readonly movements: readonly Readonly<{ source: SourceClass; destination: DestinationAudience }>[]
	readonly tools: readonly (FileToolName | NextcloudToolName)[]
	readonly gatewayOrigin: string
	readonly sourceClasses: readonly SourceClass[]
	/** Gateway origin does not attest which downstream model/provider it uses. */
	readonly downstreamPolicy: 'unverified' | 'configured'
	readonly developmentLoopback?: boolean
	/** Fixed native backend that narrows before opening any file contents. */
	readonly boundedContentSearch?: boolean
	readonly limits?: Partial<TurnLimits>
}

export interface PublishedTool { readonly name: string; readonly risk: ToolRisk }
export interface NativeResource {
	readonly resourceId: string
	readonly revision: string
	readonly sourceClass: SourceClass
	/** Authoritative native sharing classification, not inferred from file permission bits. */
	readonly audience: DestinationAudience
	/** Which configured native scope the server used when resolving this object. */
	readonly scope: FileSourceScope
}

export interface AuthorizedResource extends NativeResource { readonly epoch: number }
export interface ResourceResolver {
	/** Must reauthorize native ACL and configured roots server-side. No browser permission boolean. */
	(reference: string, purpose: ResourcePurpose, scopes: readonly FileSourceScope[], signal: AbortSignal): Promise<NativeResource | undefined>
}

const risks = new Set(['read', 'write', 'destructive'])
const encoder = new TextEncoder()

export function fileReference(value: unknown): string {
	if (typeof value !== 'string' || !/^nextcloud:files:[1-9]\d{0,14}$/.test(value)
		|| !Number.isSafeInteger(Number(value.slice(16)))) throw new ScopeViolation('invalid_reference', 'Use a canonical Nextcloud file reference, never a path or URL.')
	return value
}

function origin(value: string, development = false): string {
	try {
		const url = new URL(value)
		const loopback = development && url.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)
		if ((!loopback && url.protocol !== 'https:') || url.username || url.password || url.pathname !== '/' || url.search || url.hash || url.origin !== value) throw new Error()
		return url.origin
	} catch { throw new ScopeViolation('invalid_origin', 'A configured HTTPS origin is required.') }
}

function validateScope(value: FileSourceScope): FileSourceScope {
	if (value.kind === 'user-files') return Object.freeze({ kind: 'user-files' })
	if (value.kind !== 'file' && value.kind !== 'folder') throw new ScopeViolation('invalid_scope', 'This provider scope is unavailable.')
	return Object.freeze({ kind: value.kind, resourceId: fileReference(value.resourceId) })
}
function scopeKey(value: FileSourceScope): string { return value.kind === 'user-files' ? 'user-files' : `${value.kind}:${value.resourceId}` }

function identityKey(identity: ScopeIdentity): string {
	return JSON.stringify([identity.nextcloudOrigin, identity.nextcloudUser, identity.nextcloudBinding,
		identity.fadenstackOrigin, identity.fadenstackPrincipal.kind, identity.fadenstackPrincipal.id, identity.agent, identity.agentVersion,
		identity.schemaVersion, identity.contractVersion, identity.policyVersion])
}

/**
 * Turn-local restriction layer; central SDK modes/contracts and native ACL checks remain mandatory.
 * It contains no credentials, fetch proxy, prompt content or native mutation executor.
 */
export class TurnScope {

	readonly identity: Readonly<ScopeIdentity>
	readonly policy: Readonly<ScopePolicy>
	readonly budget: TurnBudget
	readonly #key: string
	readonly #known = new Set<string>()
	readonly #grants = new WeakSet<object>()
	readonly #tools = new Map<string, ToolRisk>()
	#epoch = 0
	#closed = false

	constructor(identity: ScopeIdentity, policy: ScopePolicy, published: readonly PublishedTool[],
		readonly current: () => ScopeIdentity, readonly resolve: ResourceResolver, now = Date.now) {
		origin(identity.nextcloudOrigin, policy.developmentLoopback)
		origin(identity.fadenstackOrigin, policy.developmentLoopback)
		if (![identity.nextcloudUser, identity.nextcloudBinding, identity.fadenstackPrincipal.id, identity.agent,
			identity.schemaVersion, identity.contractVersion, identity.policyVersion].every(value => typeof value === 'string' && value.length > 0 && encoder.encode(value).length <= 1024)
			|| !['subject', 'session'].includes(identity.fadenstackPrincipal.kind)
			|| !Number.isSafeInteger(identity.agentVersion) || identity.agentVersion < 1) throw new ScopeViolation('invalid_identity', 'A verified published agent and sign-in binding are required.')
		if (policy.version !== identity.policyVersion || origin(policy.gatewayOrigin, policy.developmentLoopback) !== identity.fadenstackOrigin
			|| !policy.sources.length || policy.sources.length > 8 || policy.destinations.length > 8 || !policy.tools.length || !policy.sourceClasses.length
			|| policy.sourceClasses.some(value => !['standard', 'protected', 'unknown'].includes(value))
			|| policy.destinationAudiences.some(value => !['private', 'shared', 'public', 'unknown'].includes(value))
			|| policy.movements.some(rule => !['standard', 'protected', 'unknown'].includes(rule.source) || !['private', 'shared', 'public', 'unknown'].includes(rule.destination))
			|| !['configured', 'unverified'].includes(policy.downstreamPolicy)) throw new ScopeViolation('invalid_policy', 'A current native scope and processing policy are required.')
		const sources = policy.sources.map(validateScope)
		const destinations = policy.destinations.map(validateScope)
		if (new Set(sources.map(scopeKey)).size !== sources.length || new Set(destinations.map(scopeKey)).size !== destinations.length
			|| new Set(policy.tools).size !== policy.tools.length) throw new ScopeViolation('invalid_scope', 'Duplicate scopes or tools are not accepted.')
		this.identity = Object.freeze({ ...identity, fadenstackPrincipal: Object.freeze({ ...identity.fadenstackPrincipal }) })
		this.#key = identityKey(identity)
		this.policy = Object.freeze({
			...policy,
			sources: Object.freeze(sources),
			destinations: Object.freeze(destinations),
			tools: Object.freeze([...policy.tools]),
			destinationAudiences: Object.freeze([...policy.destinationAudiences]),
			movements: Object.freeze(policy.movements.map(rule => Object.freeze({ ...rule }))),
			sourceClasses: Object.freeze([...policy.sourceClasses]),
			limits: policy.limits ? Object.freeze({ ...policy.limits }) : undefined,
		})
		this.budget = new TurnBudget(policy.limits, now)
		for (const name of policy.tools) {
			const host = fileCapability(name)
			const matches = published.filter(tool => tool.name === name)
			const contract = matches.length === 1 ? matches[0] : undefined
			if (!host) throw new ScopeViolation('unknown_tool', 'This tool has no reviewed host capability.')
			if (!contract || !risks.has(contract.risk)) continue
			this.#tools.set(name, stricterRisk(host.risk, contract.risk))
		}
		for (const source of [...sources, ...destinations]) if (source.kind !== 'user-files') this.#known.add(source.resourceId)
		this.assertCurrent()
	}

	assertCurrent(): void {
		if (this.#closed || identityKey(this.current()) !== this.#key) {
			this.invalidate()
			throw new ScopeViolation('scope_changed', 'The account, agent or scope changed. Start a new request.')
		}
		this.budget.assertLive()
	}

	get offeredTools(): readonly NextcloudToolName[] {
		this.assertCurrent()
		return Object.freeze([...this.#tools.keys()].filter(name => name !== 'nc_search_content' || this.policy.sources.some(source => source.kind === 'user-files') || this.policy.boundedContentSearch === true)
			.filter(name => name !== 'nc_search_files' || this.policy.sources.some(source => source.kind !== 'file'))
			.filter(name => name !== 'nc_list_folder' || this.policy.sources.some(source => source.kind !== 'file'))
			.filter(name => name !== 'nc_files_organize_read' || this.policy.sources.some(source => source.kind !== 'file'))
			.filter(name => fileCapability(name)!.risk === 'read' || this.policy.destinations.length > 0) as NextcloudToolName[])
	}

	get durablePrincipalBinding(): boolean { return this.identity.fadenstackPrincipal.kind === 'subject' }

	/** Call at the SDK's governed invocation boundary; does not replace its mode/approval checks. */
	beginTool(name: string, bulkItems = 1): ToolRisk {
		this.assertCurrent()
		const risk = this.#tools.get(name)
		if (!risk || !this.offeredTools.includes(name as FileToolName)) throw new ScopeViolation('tool_unavailable', 'This tool is unavailable in the current scope.')
		this.budget.begin(fileCapability(name)!.risk !== 'read', bulkItems)
		return risk
	}

	/** Apply before querying; filtering a broader result later can leak counts/excerpts. */
	searchScope(requested: unknown = 'all'): string {
		this.assertCurrent()
		if (requested !== 'all') {
			const reference = fileReference(requested)
			if (this.policy.sources.some(source => source.kind === 'user-files' || (source.kind === 'folder' && source.resourceId === reference))) return reference
			throw new ScopeViolation('search_scope', 'Choose a configured source folder.')
		}
		if (this.policy.sources.some(source => source.kind === 'user-files')) return 'all'
		if (this.policy.sources.length === 1 && this.policy.sources[0].kind === 'folder') return this.policy.sources[0].resourceId
		throw new ScopeViolation('search_scope', 'Choose a configured source folder before searching.')
	}

	/** Only native user selection or already scoped native discovery may introduce a reference. */
	async bindSource(reference: string, signal: AbortSignal): Promise<AuthorizedResource> {
		return this.#authorize(fileReference(reference), 'metadata', signal)
	}

	async authorize(reference: unknown, purpose: ResourcePurpose, signal: AbortSignal, expectedRevision?: string): Promise<AuthorizedResource> {
		this.assertCurrent()
		const id = fileReference(reference)
		if (!this.#known.has(id)) throw new ScopeViolation('unknown_reference', 'Use a source returned by scoped discovery or included by the user.')
		const result = await this.#authorize(id, purpose, signal)
		if (expectedRevision !== undefined && result.revision !== expectedRevision) throw new ScopeViolation('revision_changed', 'The source changed. Read it again before continuing.')
		return result
	}

	/** A source file does not grant permission to create in its parent; resolve a destination explicitly. */
	async authorizeDestination(reference: unknown, signal: AbortSignal): Promise<AuthorizedResource> {
		this.assertCurrent()
		const id = fileReference(reference)
		if (!this.#known.has(id)) throw new ScopeViolation('unknown_reference', 'Choose a known destination before preparing the action.')
		return this.#authorize(id, 'write', signal)
	}

	/** Host-only: introduce a destination resolved from an explicit native choice or exact server proposal. */
	async bindDestination(reference: string, signal: AbortSignal): Promise<AuthorizedResource> {
		return this.#authorize(fileReference(reference), 'write', signal)
	}

	async #authorize(reference: string, purpose: ResourcePurpose, signal: AbortSignal): Promise<AuthorizedResource> {
		this.assertCurrent()
		signal.throwIfAborted()
		const scopes = purpose === 'write' ? this.policy.destinations : this.policy.sources
		const exactOnly = scopes.every(source => source.kind === 'file')
		if (!scopes.length || (exactOnly && !scopes.some(source => source.kind === 'file' && source.resourceId === reference))) {
			throw new ScopeViolation('source_forbidden', 'This source is outside the configured agent scope.')
		}
		try {
			const result = await this.resolve(reference, purpose, scopes, signal)
			this.assertCurrent()
			signal.throwIfAborted()
			if (!result || fileReference(result.resourceId) !== reference || !result.revision
				|| !scopes.some(source => scopeKey(source) === scopeKey(result.scope) && (source.kind !== 'file' || source.resourceId === reference))
				|| !['standard', 'protected', 'unknown'].includes(result.sourceClass)
				|| !['private', 'shared', 'public', 'unknown'].includes(result.audience)) throw new ScopeViolation('source_forbidden', 'This source is unavailable in the configured agent scope.')
			if (!this.#known.has(reference) && this.#known.size >= this.budget.limits.records) throw new ScopeViolation('resource_limit', 'This request reached its source limit. Narrow the request.')
			const grant = Object.freeze({ ...result, scope: validateScope(result.scope), epoch: this.#epoch })
			this.#known.add(reference)
			this.#grants.add(grant)
			return grant
		} catch (error) { this.#known.delete(reference); throw error }
	}

	/** Reauthorize cached/loaded sources before their disclosure. No assertion about the gateway's downstream provider. */
	async assertEgress(destination: string, resources: readonly AuthorizedResource[], signal: AbortSignal): Promise<void> {
		this.assertCurrent()
		if (origin(destination, this.policy.developmentLoopback) !== this.policy.gatewayOrigin) throw new ScopeViolation('destination_forbidden', 'This processing destination is not configured.')
		for (const resource of resources) {
			if (!this.#grants.has(resource) || resource.epoch !== this.#epoch) throw new ScopeViolation('unknown_source', 'An authorized source binding is required.')
			const current = await this.authorize(resource.resourceId, 'read', signal, resource.revision)
			if (current.sourceClass === 'unknown' || !this.policy.sourceClasses.includes(current.sourceClass)
				|| (current.sourceClass === 'protected' && this.policy.downstreamPolicy !== 'configured')) throw new ScopeViolation('processing_forbidden', 'This source cannot be sent to the configured processing destination.')
		}
		this.assertCurrent()
	}

	/** Source and destination ACLs do not by themselves authorize moving content to a broader audience. */
	async assertMovement(resources: readonly AuthorizedResource[], destination: AuthorizedResource, signal: AbortSignal): Promise<void> {
		this.assertCurrent()
		if (!this.#grants.has(destination) || destination.epoch !== this.#epoch) throw new ScopeViolation('unknown_destination', 'An authorized destination binding is required.')
		const target = await this.authorizeDestination(destination.resourceId, signal)
		if (target.revision !== destination.revision || target.audience === 'unknown' || !this.policy.destinationAudiences.includes(target.audience)) {
			throw new ScopeViolation('movement_forbidden', 'This destination changed or its audience is not allowed.')
		}
		for (const resource of resources) {
			if (!this.#grants.has(resource) || resource.epoch !== this.#epoch) throw new ScopeViolation('unknown_source', 'An authorized source binding is required.')
			const source = await this.authorize(resource.resourceId, 'read', signal, resource.revision)
			if (source.sourceClass === 'unknown' || !this.policy.movements.some(rule => rule.source === source.sourceClass && rule.destination === target.audience)) {
				throw new ScopeViolation('movement_forbidden', 'This source cannot be copied to the destination audience.')
			}
		}
		this.assertCurrent()
	}

	invalidate(): void { this.#closed = true; this.#epoch++; this.#known.clear(); this.#tools.clear() }

}
