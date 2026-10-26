import { ScopeViolation } from './limits.ts'
import type { DestinationAudience, NativeResource, ScopePolicy, SourceClass } from './scope.ts'

/** Content already in a conversation keeps its original classification after a file moves or changes. */
export class SourceLedger {

	readonly #classes = new Map<string, Set<SourceClass>>()

	constructor(previous?: SourceLedger) {
		if (previous) for (const [reference, classes] of previous.#classes) this.#classes.set(reference, new Set(classes))
	}

	get references(): readonly string[] { return [...this.#classes.keys()] }

	record(resource: NativeResource): void {
		let classes = this.#classes.get(resource.resourceId)
		if (!classes) {
			if (this.#classes.size >= 200) throw new ScopeViolation('resource_limit', 'Start a new conversation to use more sources.')
			classes = new Set()
			this.#classes.set(resource.resourceId, classes)
		}
		classes.add(resource.sourceClass)
	}

	assertProcessing(policy: ScopePolicy): void {
		for (const classes of this.#classes.values()) {
			for (const source of classes) {
				if (source === 'unknown' || !policy.sourceClasses.includes(source)
				|| (source === 'protected' && policy.downstreamPolicy !== 'configured')) throw new ScopeViolation('processing_forbidden', 'Earlier conversation content is outside the current processing policy. Start a new conversation.')
			}
		}
	}

	assertMovement(policy: ScopePolicy, audience: DestinationAudience): void {
		for (const classes of this.#classes.values()) {
			for (const source of classes) {
				if (source === 'unknown' || !policy.movements.some(rule => rule.source === source && rule.destination === audience)) throw new ScopeViolation('movement_forbidden', 'Earlier conversation content cannot be copied to this destination audience.')
			}
		}
	}

	clear(): void { this.#classes.clear() }

}
