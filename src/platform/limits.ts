export interface TurnLimits {
	readonly calls: number
	readonly mutations: number
	readonly elapsedMs: number
	readonly resultBytes: number
	readonly records: number
	readonly bulkItems: number
}

export const DEFAULT_TURN_LIMITS: Readonly<TurnLimits> = Object.freeze({
	calls: 30, mutations: 10, elapsedMs: 180000, resultBytes: 256 * 1024, records: 200, bulkItems: 20,
})

export class ScopeViolation extends Error {

	constructor(readonly code: string, message: string) { super(message); this.name = 'ScopeViolation' }

}

/** Counts dispatch attempts, not successful effects. Cancellation/retry never refunds an attempt. */
export class TurnBudget {

	readonly limits: Readonly<TurnLimits>
	readonly #started: number
	#calls = 0
	#mutations = 0
	#bytes = 0
	#records = 0

	constructor(limits: Partial<TurnLimits> = {}, readonly now: () => number = Date.now) {
		const merged = { ...DEFAULT_TURN_LIMITS, ...limits }
		for (const [key, value] of Object.entries(merged)) {
			if (!Number.isSafeInteger(value) || value < 1 || value > DEFAULT_TURN_LIMITS[key as keyof TurnLimits]) {
				throw new ScopeViolation('invalid_limit', 'Turn limits must be positive and cannot exceed the host limits.')
			}
		}
		this.limits = Object.freeze(merged)
		this.#started = now()
	}

	assertLive(): void {
		const elapsed = this.now() - this.#started
		if (elapsed < 0 || elapsed >= this.limits.elapsedMs) throw new ScopeViolation('turn_expired', 'This request reached its time limit. Start a new request.')
	}

	begin(mutation: boolean, bulkItems = 1): void {
		this.assertLive()
		if (!Number.isSafeInteger(bulkItems) || bulkItems < 1 || bulkItems > this.limits.bulkItems) throw new ScopeViolation('bulk_limit', 'This action exceeds the item limit.')
		if (this.#calls >= this.limits.calls || (mutation && this.#mutations >= this.limits.mutations)) {
			throw new ScopeViolation('action_limit', 'This request reached its action limit. Start a new request.')
		}
		this.#calls++
		if (mutation) this.#mutations++
	}

	/** Call before disclosing a projected result; reject the whole result rather than silently truncate text. */
	consumeResult(value: unknown, records = 1): void {
		this.assertLive()
		if (!Number.isSafeInteger(records) || records < 0) throw new ScopeViolation('invalid_result', 'Invalid result size.')
		const encoded = JSON.stringify(value)
		if (encoded === undefined) throw new ScopeViolation('invalid_result', 'A structured result is required.')
		const bytes = new TextEncoder().encode(encoded).byteLength
		if (this.#bytes + bytes > this.limits.resultBytes || this.#records + records > this.limits.records) {
			throw new ScopeViolation('result_limit', 'This request reached its result limit. Narrow the request.')
		}
		this.#bytes += bytes
		this.#records += records
	}

	get usage() { return Object.freeze({ calls: this.#calls, mutations: this.#mutations, resultBytes: this.#bytes, records: this.#records }) }

}
