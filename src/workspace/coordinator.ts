import { shallowReactive } from 'vue'
import type { WriteResult } from '../integrations/nextcloud/types.ts'
import type { NativeView, NativeViewGuard } from './nativeView.ts'

export type ViewOutcome = 'refreshed' | 'refresh_required' | 'refresh_failed' | 'not_visible' | 'unchanged'
export interface WorkspaceReceipt {
	id: string
	operation: string
	target: string
	resourceId?: number
	outcome: 'committed' | 'conflict' | 'cancelled_before_dispatch' | 'unknown_completion' | 'not_dispatched' | 'failed'
	view: ViewOutcome
	message?: string
}
export interface MutationBinding { readonly view: Readonly<NativeView>; readonly app: string }
export interface WorkspaceDependencies {
	guard: Pick<NativeViewGuard, 'snapshot' | 'dispose'>
	refresh(result: WriteResult, signal: AbortSignal): Promise<ViewOutcome>
	prepareProvider?(app: string): Promise<void>
}

/** UI coordination only. Native permissions and the SDK remain the execution authorities. */
export class WorkspaceCoordinator {

	readonly state = shallowReactive({ phase: 'idle' as 'idle' | 'dispatching' | 'refreshing', locked: false, latest: undefined as WorkspaceReceipt | undefined })
	#owner: string | undefined
	#sequence = 0

	constructor(readonly dependencies: WorkspaceDependencies) {}

	prepare(app: string): MutationBinding {
		const view = this.dependencies.guard.snapshot()
		if (!allowsDispatch(view, app)) throw new Error(view.reason)
		return Object.freeze({ app, view: Object.freeze({ ...view }) })
	}

	async prepareProvider(app: string): Promise<MutationBinding> {
		await this.dependencies.prepareProvider?.(app)
		return this.prepare(app)
	}

	begin(id: string, binding: MutationBinding): number {
		if (this.#owner) throw new Error('Another change is still being reconciled.')
		const view = this.dependencies.guard.snapshot()
		if (view.key !== binding.view.key || view.epoch !== binding.view.epoch) throw new Error('The native view changed. Review a new proposal before saving.')
		if (!allowsDispatch(view, binding.app)) throw new Error(view.reason)
		this.#owner = id
		this.state.phase = 'dispatching'
		this.state.locked = view.app === binding.app
		return ++this.#sequence
	}

	async committed(id: string, generation: number, binding: MutationBinding, result: WriteResult, signal: AbortSignal): Promise<WorkspaceReceipt> {
		if (this.#owner !== id || generation !== this.#sequence) throw new Error('The active change no longer matches this result.')
		// A confirmed save stays confirmed even when Stop interrupts a subsequent refresh.
		const receipt: WorkspaceReceipt = { id, operation: result.operation, resourceId: result.file.id, target: result.file.name, outcome: 'committed', view: 'refresh_required' }
		this.state.latest = receipt
		try {
			const view = this.dependencies.guard.snapshot()
			if (view.app !== binding.app || view.key !== binding.view.key || view.epoch !== binding.view.epoch) receipt.view = 'not_visible'
			else if (result.operation === 'revalidate') receipt.view = 'unchanged'
			else if (view.editState !== 'clean' || signal.aborted) receipt.view = 'refresh_required'
			else {
				this.state.phase = 'refreshing'
				const refreshing = AbortSignal.any([signal, AbortSignal.timeout(15000)])
				try { receipt.view = await untilAborted(this.dependencies.refresh(result, refreshing), refreshing) } catch { receipt.view = signal.aborted ? 'refresh_required' : 'refresh_failed' }
			}
			this.state.latest = { ...receipt }
			return receipt
		} finally { this.release(id, generation) }
	}

	/** Each compound effect rechecks the native view before its next dispatch. */
	assertContinuation(id: string, generation: number, binding: MutationBinding): void {
		const view = this.dependencies.guard.snapshot()
		if (this.#owner !== id || generation !== this.#sequence || view.key !== binding.view.key || view.epoch !== binding.view.epoch
			|| !allowsDispatch(view, binding.app)) throw new Error('The native view changed. Further dispatch is stopped; inspect the partial action.')
	}

	notDispatched(id: string, generation: number, target: string, message: string, operation: string): void {
		if (this.#owner !== id || generation !== this.#sequence) return
		this.state.latest = { id, operation, target, outcome: 'not_dispatched', view: 'unchanged', message }
		this.release(id, generation)
	}

	failed(id: string, generation: number, target: string, error: unknown, operation = 'write'): void {
		if (this.#owner !== id || generation !== this.#sequence) return
		const status = (error as { response?: { status?: number } })?.response?.status
		const partial = (error as { response?: { data?: { outcome?: string } } })?.response?.data?.outcome === 'partial'
		this.state.latest = { id, operation, target, outcome: status === 409 || status === 412 ? 'conflict' : 'unknown_completion', view: 'refresh_required', message: operation === 'revalidate' ? 'File revision verification could not be confirmed. Review the file and action history before retrying.' : status === 409 || status === 412 ? 'The native resource changed. Review a new proposal.' : 'The request may have reached Nextcloud. Inspect the resource before trying again.' }
		if (partial) this.state.latest = { ...this.state.latest, outcome: 'unknown_completion', message: 'Some native steps completed. A send may already have been accepted. Inspect the recorded action before making another change; nothing will be resent automatically.' }
		this.release(id, generation)
	}

	private release(id: string, generation: number): void {
		if (this.#owner !== id || generation !== this.#sequence) return
		this.#owner = undefined
		this.state.locked = false
		this.state.phase = 'idle'
	}

	dismissReceipt(): void { this.state.latest = undefined }

	dispose(): void { this.#sequence++; this.#owner = undefined; this.state.locked = false; this.state.phase = 'idle'; this.dependencies.guard.dispose() }

}

function allowsDispatch(view: NativeView, app: string): boolean {
	if (['windmill', 'n8n'].includes(app) && view.app === 'engine-workspace') return false
	return view.app !== app || view.editState === 'clean' || (['windmill', 'n8n'].includes(app) && view.handoff === 'paused-engine-editor')
}

function untilAborted<T>(work: Promise<T>, signal: AbortSignal): Promise<T> {
	return new Promise((resolve, reject) => {
		const aborted = () => reject(signal.reason)
		if (signal.aborted) reject(signal.reason)
		else signal.addEventListener('abort', aborted, { once: true })
		work.then(value => { signal.removeEventListener('abort', aborted); resolve(value) }, error => { signal.removeEventListener('abort', aborted); reject(error) })
	})
}
