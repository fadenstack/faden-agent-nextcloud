import type { TurnBudget } from '../platform/limits.ts'
import { ScopeViolation } from '../platform/limits.ts'
import type { DestinationAudience, SourceClass } from '../platform/scope.ts'
import type { AvailableProviderTool, ProviderId } from './registry.ts'

export interface ProviderResource {
	readonly provider: ProviderId
	readonly resourceId: string
	readonly collectionId: string
	readonly revision: string
	readonly sourceClass: SourceClass
	readonly audience: DestinationAudience
	readonly policyVersion: string
	readonly filename?: string
}

/** Resource handles are issued by native discovery; decoding one never grants access. */
export function providerReference(value: unknown, provider: ProviderId): string {
	if (typeof value !== 'string' || !value.startsWith(`nextcloud:${provider}:`)
		|| !/^[A-Za-z0-9_-]{1,1024}$/.test(value.slice(`nextcloud:${provider}:`.length))) { throw new ScopeViolation('invalid_reference', 'Use a native provider resource reference, never a path or URL.') }
	return value
}

/** Extends the turn's existing budget; providers do not receive separate allowances. */
export class ProviderTurnScope {
	readonly #known = new Map<string, Readonly<ProviderResource>>()
	#closed = false

	constructor(
		readonly tools: readonly AvailableProviderTool[],
		readonly budget: TurnBudget,
		readonly assertIdentity: () => void,
		readonly resolve: (resource: Readonly<ProviderResource>, write: boolean, signal: AbortSignal) => Promise<ProviderResource>,
	) {}

	private current(): void {
		if (this.#closed) {
			throw new ScopeViolation('scope_changed', 'The provider context changed. Start a new request.')
		}
		this.budget.assertLive()
		this.assertIdentity()
	}

	begin(name: string): AvailableProviderTool {
		this.current()
		const tool = this.tools.find((item) => item.name === name)
		if (!tool) {
			throw new ScopeViolation('tool_unavailable', 'This provider tool is unavailable in the current scope.')
		}
		this.budget.begin(tool.risk !== 'read')
		return tool
	}

	/** Call only for results of an authenticated, scoped native discovery or user selection. */
	bind(resource: ProviderResource): void {
		this.current()
		providerReference(resource.resourceId, resource.provider)
		if (typeof resource.revision !== 'string' || !resource.revision || resource.revision.length > 2048
			|| typeof resource.collectionId !== 'string' || !resource.collectionId || resource.collectionId.length > 1024
			|| typeof resource.policyVersion !== 'string' || !resource.policyVersion || resource.policyVersion.length > 200
			|| !['standard', 'protected', 'unknown'].includes(resource.sourceClass)
			|| !['private', 'shared', 'public', 'unknown'].includes(resource.audience)
			|| !this.tools.some((tool) => tool.provider === resource.provider && tool.capability.policyVersion === resource.policyVersion)) { throw new ScopeViolation('source_forbidden', 'The native provider resource is outside the current policy.') }
		if (!this.#known.has(resource.resourceId) && this.#known.size >= this.budget.limits.records) {
			throw new ScopeViolation('resource_limit', 'Narrow the provider request.')
		}
		this.#known.set(resource.resourceId, Object.freeze({ ...resource }))
	}

	async authorize(reference: unknown, provider: ProviderId, write: boolean, signal: AbortSignal): Promise<Readonly<ProviderResource>> {
		this.current()
		const id = providerReference(reference, provider)
		const known = this.#known.get(id)
		if (!known || known.provider !== provider) {
			throw new ScopeViolation('unknown_reference', 'Use a resource returned by native discovery or explicitly included by the user.')
		}
		signal.throwIfAborted()
		const current = await this.resolve(known, write, signal)
		this.current()
		signal.throwIfAborted()
		if (current.provider !== known.provider || current.resourceId !== id || current.collectionId !== known.collectionId
			|| current.policyVersion !== known.policyVersion || current.revision !== known.revision
			|| current.audience !== known.audience || current.sourceClass !== known.sourceClass) { throw new ScopeViolation('revision_changed', 'The resource, audience or policy changed. Read it again before continuing.') }
		return Object.freeze({ ...current })
	}

	invalidate(): void {
		this.#closed = true
		this.#known.clear()
	}
}
