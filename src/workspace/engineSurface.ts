import { shallowReactive } from 'vue'
import type { NativeView } from './nativeView.ts'
import type { NativeProvidersApi } from '../providers/client.ts'
import type { WriteResult } from '../integrations/nextcloud/types.ts'
import type { ViewOutcome } from './coordinator.ts'

export interface EngineSurfaceDescriptor {
	connectionId: string
	provider: 'windmill' | 'n8n'
	label: string
	url: string
	origin: string
	revision: string
	apiPrincipal?: string | null
}

export interface WorkflowSummary {
	readonly reference: string
	readonly name: string
	readonly revision?: string
}

export interface WorkflowTarget {
	readonly connectionId: string
	readonly provider: 'windmill' | 'n8n'
	readonly reference: string
	readonly sourceKind: 'saved' | 'own-draft'
	readonly name: string
	readonly revision: string
	readonly copySource?: {
		readonly reference: string
		readonly kind: string
		readonly revision: string
	}
	readonly apiPrincipal?: string | null
}

export interface WorkflowResultRecord {
	readonly connectionId: string
	readonly actionId: string
	readonly operation: string
	readonly sourceReference?: string
	readonly sourceKind?: string
	readonly createdReference: string
	readonly createdName: string
	readonly provider: 'windmill' | 'n8n'
	readonly isCopy: boolean
}

export interface WorkflowSource { readonly reference: string; readonly kind: 'saved' | 'own-draft'; readonly revision: string }

export function workflowReference(provider: 'windmill' | 'n8n', value: unknown): string {
	if (typeof value !== 'string' || value.length > 255 || !(provider === 'windmill' ? /^[uf]\/[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)+$/ : /^[a-zA-Z0-9_-]{1,64}$/).test(value)) throw new Error('Invalid native workflow reference.')
	return value
}

export function workflowSource(provider: 'windmill' | 'n8n', value: unknown): Readonly<WorkflowSource> {
	if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('Missing native workflow source binding.')
	const v = value as Record<string, unknown>
	if (Object.keys(v).length !== 3 || !['saved', ...(provider === 'windmill' ? ['own-draft'] : [])].includes(String(v.kind)) || typeof v.revision !== 'string' || !/^[a-f0-9]{64}$/.test(v.revision)) throw new Error('Invalid native workflow source binding.')
	return Object.freeze({ reference: workflowReference(provider, v.reference), kind: v.kind as WorkflowSource['kind'], revision: v.revision })
}

export function engineSurfaceDescriptor(value: unknown): EngineSurfaceDescriptor {
	if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('Invalid native workspace.')
	const v = value as Record<string, unknown>
	const allowedKeys = ['connectionId', 'provider', 'label', 'url', 'origin', 'revision', 'apiPrincipal']
	if (Object.keys(v).some(k => !allowedKeys.includes(k))
		|| !['connectionId', 'provider', 'label', 'url', 'origin', 'revision'].every(k => k in v)
		|| typeof v.connectionId !== 'string' || !/^[a-z][a-z0-9_-]{0,31}$/.test(v.connectionId)
		|| !['windmill', 'n8n'].includes(String(v.provider)) || typeof v.label !== 'string' || !v.label || v.label.length > 160
		|| typeof v.url !== 'string' || v.url.length > 2048 || typeof v.origin !== 'string'
		|| typeof v.revision !== 'string' || !/^[a-f0-9]{64}$/.test(v.revision)
		|| ('apiPrincipal' in v && v.apiPrincipal !== null && (typeof v.apiPrincipal !== 'string' || v.apiPrincipal.length > 64))) throw new Error('Invalid native workspace.')
	const url = new URL(v.url)
	const loopback = /^127(?:\.(?:0|[1-9][0-9]{0,2})){3}$/.test(url.hostname) && url.hostname.split('.').every(part => Number(part) <= 255)
	if (url.origin !== v.origin || url.hostname === new URL(location.href).hostname || url.username || url.password || url.hash || (url.protocol !== 'https:'
		&& !(url.protocol === 'http:' && (url.hostname === 'localhost' || url.hostname.endsWith('.localhost') || loopback)))) throw new Error('Invalid native workspace destination.')
	return Object.freeze({ ...v }) as unknown as EngineSurfaceDescriptor
}

/** The editor is opaque. A human pause is a handoff, never evidence of cleanliness. */
export class EngineSurface {

	readonly state: {
		paused: boolean
		busy: boolean
		generation: number
		epoch: number
		target: WorkflowTarget | undefined
		currentUrl: string
		refreshAuthorized: boolean
		lastResult: WorkflowResultRecord | undefined
		pendingNavigation: { url: string; epoch: number } | undefined
		loadStatus: 'unknown' | 'loading' | 'loaded-unverified' | 'stopped' | 'failed'
		connectionRevision: string | undefined
		connectionError: string | undefined
	}

	api: NativeProvidersApi | undefined

	#unsubscribe: (() => void) | undefined
	#channel: BroadcastChannel | undefined
	#selection = 0
	#loaded: { generation: number; resolve: () => void } | undefined

	constructor(readonly descriptor: EngineSurfaceDescriptor) {
		this.state = shallowReactive({
			paused: false,
			busy: false,
			generation: 0,
			epoch: 0,
			target: undefined,
			currentUrl: descriptor.url,
			refreshAuthorized: false,
			lastResult: undefined,
			pendingNavigation: undefined,
			loadStatus: 'unknown',
			connectionRevision: undefined,
			connectionError: undefined,
		})
		if (typeof BroadcastChannel !== 'undefined') {
			try {
				this.#channel = new BroadcastChannel('fadenstack_engine_connections')
				this.#channel.onmessage = event => {
					const data = event?.data as { connectionId?: unknown; revision?: unknown; connected?: unknown } | undefined
					if (data && typeof data.connectionId === 'string' && typeof data.revision === 'string' && typeof data.connected === 'boolean') {
						this.observeConnection(data.connectionId, data.revision, data.connected, true)
					}
				}
			} catch { /* Same-page invalidation and server revision checks still apply. */ }
		}
	}

	get apiPrincipal(): string | null | undefined {
		return this.descriptor.apiPrincipal
	}

	invalidateConnection(reason = 'The workflow connection or account changed. Reload the native workspace before continuing.'): void {
		this.#selection++
		this.state.target = undefined
		this.state.lastResult = undefined
		this.state.pendingNavigation = undefined
		this.state.refreshAuthorized = false
		this.state.paused = false
		this.state.connectionError = reason
		this.state.epoch++
	}

	observeConnection(connectionId: string, revision: string, connected: boolean, mutated = false): void {
		if (connectionId !== this.descriptor.connectionId) return
		if (!connected) {
			this.invalidateConnection('The workflow connection was disconnected. Reconnect your account and reload the native workspace.')
			return
		}
		if (mutated || (this.state.connectionRevision !== undefined && this.state.connectionRevision !== revision)) {
			this.invalidateConnection('The workflow connection or account changed. Reload the native workspace before continuing.')
			return
		}
		this.state.connectionRevision = revision
	}

	loaded(generation = this.state.generation): void {
		if (generation !== this.state.generation) return
		this.#selection++
		this.state.refreshAuthorized = false
		this.state.epoch++
		this.state.loadStatus = 'loaded-unverified'
		if (this.#loaded?.generation === generation) this.#loaded.resolve()
	}

	pause(): void {
		if (!this.state.busy) {
			this.state.paused = true
			this.state.epoch++
		}
	}

	resume(): void {
		if (!this.state.busy) {
			this.state.paused = false
			this.state.refreshAuthorized = false
			this.state.epoch++
		}
	}

	authorizeRefresh(allowed: boolean): void {
		if (!this.state.busy) {
			this.state.refreshAuthorized = allowed && this.state.paused
		}
	}

	setTarget(target: WorkflowTarget | undefined): void {
		if (this.state.busy) return
		this.#selection++
		if (target) {
			const source = workflowSource(this.descriptor.provider, { reference: target.reference, kind: target.sourceKind, revision: target.revision })
			if (target.connectionId !== this.descriptor.connectionId || target.provider !== this.descriptor.provider) throw new Error('The workflow target belongs to another connection.')
			target = { ...target, copySource: source }
		}
		this.state.target = target ? Object.freeze({ ...target }) : undefined
		this.state.pendingNavigation = undefined
		this.state.refreshAuthorized = false
		this.state.epoch++
	}

	targetUrl(reference: string): string {
		workflowReference(this.descriptor.provider, reference)
		if (this.descriptor.provider === 'windmill') {
			const parsed = new URL(this.descriptor.url)
			const workspace = parsed.searchParams.get('workspace')
			const encoded = reference.split('/').map(encodeURIComponent).join('/')
			parsed.pathname = `/flows/edit/${encoded}`
			if (workspace) parsed.searchParams.set('workspace', workspace)
			else parsed.searchParams.delete('workspace')
			parsed.hash = ''
			return parsed.href
		}
		const base = new URL(this.descriptor.origin)
		base.pathname = `/workflow/${encodeURIComponent(reference)}`
		base.search = ''
		base.hash = ''
		return base.href
	}

	navigateChild(url: string): void {
		if (this.state.busy) return
		const parsed = new URL(url)
		if (parsed.origin !== this.descriptor.origin || parsed.username || parsed.password) throw new Error('Invalid child navigation destination.')
		this.state.pendingNavigation = { url: parsed.href, epoch: this.state.epoch }
	}

	cancelNavigation(): void { this.state.pendingNavigation = undefined }

	confirmNavigation(): void {
		const pending = this.state.pendingNavigation
		if (this.state.busy || !pending) return
		if (pending.epoch !== this.state.epoch) { this.cancelNavigation(); return }
		this.state.paused = false
		this.replaceChild(pending.url)
	}

	private replaceChild(url: string): void {
		this.#selection++
		this.state.pendingNavigation = undefined
		this.state.currentUrl = url
		this.state.refreshAuthorized = false
		this.state.generation++
		this.state.epoch++
		this.state.loadStatus = 'loading'
	}

	navigateToTarget(target: WorkflowTarget): void {
		this.navigateChild(this.targetUrl(target.reference))
	}

	recordResult(result: {
		connectionId: string
		actionId: string
		generation: number
		operation: string
		sourceReference?: string
		sourceKind?: string
		createdReference: string
		createdName: string
		provider: 'windmill' | 'n8n'
		isCopy?: boolean
	}): void {
		if (result.provider !== this.descriptor.provider || result.connectionId !== this.descriptor.connectionId || result.generation !== this.state.generation) return
		if (!/^[a-f0-9]{32}$/.test(result.actionId)) throw new Error('Invalid native action receipt.')
		workflowReference(result.provider, result.createdReference)
		if (result.sourceReference) workflowReference(result.provider, result.sourceReference)
		const isCopy = result.isCopy ?? (!!result.sourceReference && result.sourceReference !== result.createdReference)
		this.state.lastResult = Object.freeze({
			...result,
			isCopy,
		})
	}

	dismissResult(): void {
		this.state.lastResult = undefined
	}

	async refresh(result: WriteResult, signal: AbortSignal = new AbortController().signal): Promise<ViewOutcome> {
		signal.throwIfAborted()
		if (result.provider?.app !== this.descriptor.provider || result.provider.collectionId !== this.descriptor.connectionId) return 'not_visible'
		// An opaque load cannot certify the editor's sign-in, route, or rendered data.
		if (this.descriptor.provider === 'n8n' || !this.state.refreshAuthorized || result.operation !== 'nc_windmill_draft') return 'refresh_required'
		const generation = this.state.generation + 1
		let timer: ReturnType<typeof setTimeout> | undefined
		let aborted: (() => void) | undefined
		try {
			const url = this.targetUrl(result.provider.filename!)
			const loaded = new Promise<void>((resolve, reject) => {
				this.#loaded = { generation, resolve }
				aborted = () => reject(signal.reason)
				signal.addEventListener('abort', aborted, { once: true })
				timer = setTimeout(() => reject(new Error('Native child load timed out.')), 10000)
			})
			this.replaceChild(url) // One explicit saved/discarded-edits grant, consumed here, even while chat is busy.
			await loaded
			return this.state.generation === generation ? 'refresh_required' : 'not_visible'
		} catch {
			if (this.state.generation === generation) this.state.loadStatus = signal.aborted ? 'stopped' : 'failed'
			return signal.aborted ? 'refresh_required' : 'refresh_failed'
		} finally {
			clearTimeout(timer)
			if (aborted) signal.removeEventListener('abort', aborted)
			if (this.#loaded?.generation === generation) this.#loaded = undefined
		}
	}

	/** Explicit user acknowledgement only; never called by an agent or receipt. */
	reloadAcknowledged(): void {
		if (this.state.busy) return
		this.state.paused = false
		this.replaceChild(this.state.currentUrl)
	}

	snapshot(): NativeView {
		if (this.state.connectionError) {
			return {
				app: this.descriptor.provider,
				key: 'engine-workspace:' + this.descriptor.connectionId + ':invalidated:' + this.state.epoch,
				epoch: this.state.epoch,
				editState: 'unknown',
				refreshAuthorized: false,
				reason: this.state.connectionError,
			}
		}
		const targetKey = this.state.target
			? `:${this.state.target.reference}:${this.state.target.sourceKind}:${this.state.target.revision}`
			: ':no-target'
		return {
			app: this.descriptor.provider,
			key: 'engine-workspace:' + this.descriptor.connectionId + ':' + this.descriptor.revision + targetKey,
			epoch: this.state.epoch,
			editState: 'unknown',
			refreshAuthorized: this.state.refreshAuthorized,
			...(this.state.paused ? { handoff: 'paused-engine-editor' as const } : {}),
			reason: 'The native workflow editor may contain unsaved edits. Pause the editor for agent changes, or save/cancel your edits. Pausing retains the editor; it does not claim that its content is saved.',
		}
	}

	bind(chat: { readonly isBusy: boolean; subscribe(listener: () => void): () => void }, api?: NativeProvidersApi): void {
		this.#unsubscribe?.()
		if (api) this.api = api
		const update = () => { this.state.busy = chat.isBusy }
		this.#unsubscribe = chat.subscribe(update)
		update()
	}

	setApi(api: NativeProvidersApi | undefined): void {
		this.api = api
	}

	unbind(): void {
		this.#unsubscribe?.(); this.#unsubscribe = undefined; this.state.busy = false
		try { this.#channel?.close() } catch { /* Ignore channel close errors */ }
		this.#channel = undefined
		this.api = undefined
		this.setTarget(undefined)
		this.state.lastResult = undefined
	}

	async loadWorkflows(signal?: AbortSignal): Promise<readonly WorkflowSummary[]> {
		if (this.state.connectionError) throw new Error(this.state.connectionError)
		if (!this.api?.contentRead) return []
		const result = await this.api.contentRead(this.descriptor.provider, { connectionId: this.descriptor.connectionId }, signal ?? new AbortController().signal) as { workflows?: readonly WorkflowSummary[]; workspaceRevision?: string }
		if (typeof result.workspaceRevision === 'string' && result.workspaceRevision !== this.descriptor.revision) {
			this.invalidateConnection('The workflow connection or account changed. Reload the native workspace before continuing.')
			throw new Error(this.state.connectionError!)
		}
		return result.workflows ?? []
	}

	async selectTarget(reference: string, sourceKind: 'saved' | 'own-draft' = 'saved', name?: string, signal?: AbortSignal): Promise<WorkflowTarget> {
		if (this.state.connectionError) throw new Error(this.state.connectionError)
		if (this.state.busy) throw new Error('Cannot change workflow target while assistant is working.')
		if (!this.api?.contentRead) throw new Error('Native providers API is unavailable.')
		workflowReference(this.descriptor.provider, reference)
		const request = ++this.#selection; const epoch = this.state.epoch; const api = this.api
		const result = await this.api.contentRead(this.descriptor.provider, {
			connectionId: this.descriptor.connectionId,
			reference,
			sourceKind,
		}, signal ?? new AbortController().signal) as {
			copySource?: { reference: string; kind: string; revision: string }
			resource?: { revision: string }
			definition?: { summary?: string; name?: string }
			workspaceRevision?: string
		}
		signal?.throwIfAborted()
		if (typeof result.workspaceRevision === 'string' && result.workspaceRevision !== this.descriptor.revision) {
			this.invalidateConnection('The workflow connection or account changed. Reload the native workspace before continuing.')
			throw new Error(this.state.connectionError!)
		}
		if (request !== this.#selection || epoch !== this.state.epoch || this.api !== api || this.state.busy) throw new Error('The workflow selection changed. Select the target again.')
		const source = workflowSource(this.descriptor.provider, result.copySource)
		if (source.reference !== reference || source.kind !== sourceKind) throw new Error('The native target differs from the requested workflow.')
		const revision = source.revision
		const targetName = name || result.definition?.summary || result.definition?.name || reference
		const target: WorkflowTarget = {
			connectionId: this.descriptor.connectionId,
			provider: this.descriptor.provider,
			reference,
			sourceKind,
			name: targetName,
			revision,
			copySource: source,
			apiPrincipal: this.descriptor.apiPrincipal,
		}
		this.setTarget(target)
		return target
	}

}

const surfaces = new WeakMap<Document, EngineSurface>()

export function installEngineSurface(page: Document): EngineSurface | undefined {
	if (!/\/apps\/fadenstack_agent\/engines\/workspace\/[a-z][a-z0-9_-]{0,31}\/?$/.test(page.location.pathname)) return
	const root = page.querySelector<HTMLElement>('#fadenstack-engine-surface')
	if (!root) return
	const surface = new EngineSurface(engineSurfaceDescriptor(JSON.parse(root.dataset.workspace ?? 'null')))
	page.body.classList.add('fadenstack-engine-workspace-page')
	surfaces.set(page, surface)
	if (typeof window !== 'undefined') (window as unknown as { __fadenstackEngineSurface?: EngineSurface }).__fadenstackEngineSurface = surface
	return surface
}
export function currentEngineSurface(page: Document): EngineSurface | undefined {
	return surfaces.get(page) ?? (typeof window !== 'undefined' ? (window as unknown as { __fadenstackEngineSurface?: EngineSurface }).__fadenstackEngineSurface : undefined)
}
export function notifyEngineConnectionChanged(connectionId: string, revision: string, connected: boolean): void {
	if (typeof document !== 'undefined') currentEngineSurface(document)?.observeConnection(connectionId, revision, connected, true)
	if (typeof BroadcastChannel !== 'undefined') {
		try {
			const channel = new BroadcastChannel('fadenstack_engine_connections')
			channel.postMessage({ connectionId, revision, connected })
			channel.close()
		} catch { /* Ignore BroadcastChannel errors */ }
	}
}
