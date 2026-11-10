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
	readonly operation: string
	readonly sourceReference?: string
	readonly sourceKind?: string
	readonly createdReference: string
	readonly createdName: string
	readonly provider: 'windmill' | 'n8n'
	readonly isCopy: boolean
	readonly navigated: boolean
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
		&& !(url.protocol === 'http:' && (url.hostname === 'localhost' || loopback)))) throw new Error('Invalid native workspace destination.')
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
	}

	api: NativeProvidersApi | undefined

	#unsubscribe: (() => void) | undefined

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
		})
	}

	get apiPrincipal(): string | null | undefined {
		return this.descriptor.apiPrincipal
	}

	loaded(): void {
		this.state.refreshAuthorized = false
		this.state.epoch++
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
			this.state.refreshAuthorized = allowed
		}
	}

	setTarget(target: WorkflowTarget | undefined): void {
		if (this.state.busy) return
		this.state.target = target ? Object.freeze({ ...target }) : undefined
		this.state.refreshAuthorized = false
		this.state.epoch++
	}

	targetUrl(reference: string): string {
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
		if (parsed.origin !== this.descriptor.origin) throw new Error('Invalid child navigation destination.')
		this.state.currentUrl = parsed.href
		this.state.paused = false
		this.state.refreshAuthorized = false
		this.state.generation++
		this.state.epoch++
	}

	navigateToTarget(target: WorkflowTarget): void {
		this.navigateChild(this.targetUrl(target.reference))
	}

	recordResult(result: {
		operation: string
		sourceReference?: string
		sourceKind?: string
		createdReference: string
		createdName: string
		provider: 'windmill' | 'n8n'
		isCopy?: boolean
	}): void {
		let navigated = false
		if (this.state.refreshAuthorized && result.provider === 'windmill' && !this.state.busy) {
			try {
				this.navigateChild(this.targetUrl(result.createdReference))
				navigated = true
			} catch { /* Preserve committed receipt if optional navigation fails */ }
		}
		const isCopy = result.isCopy ?? (!!result.sourceReference && result.sourceReference !== result.createdReference)
		this.state.lastResult = Object.freeze({
			...result,
			isCopy,
			navigated,
		})
	}

	dismissResult(): void {
		this.state.lastResult = undefined
	}

	async refresh(result: WriteResult, signal: AbortSignal = new AbortController().signal): Promise<ViewOutcome> {
		signal.throwIfAborted()
		if (this.descriptor.provider === 'n8n') {
			// Stock n8n 2.43.8 blocks different-origin framing with SAMEORIGIN.
			// Preserve framing protection; framed refresh is blocked.
			return 'refresh_required'
		}
		if (this.descriptor.provider === 'windmill') {
			const targetRef = result.provider?.filename || result.file.name
			try {
				if (targetRef && targetRef !== 'draft') {
					this.navigateToTarget({
						connectionId: this.descriptor.connectionId,
						provider: 'windmill',
						reference: targetRef,
						sourceKind: 'own-draft',
						name: targetRef,
						revision: '',
					})
				} else {
					this.reloadAcknowledged()
				}
				return 'refreshed'
			} catch {
				return 'refresh_failed'
			}
		}
		return 'refresh_required'
	}

	/** Explicit user acknowledgement only; never called by an agent or receipt. */
	reloadAcknowledged(): void {
		if (this.state.busy) return
		this.state.paused = false
		this.state.refreshAuthorized = false
		this.state.generation++
		this.state.epoch++
	}

	snapshot(): NativeView {
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

	unbind(): void { this.#unsubscribe?.(); this.#unsubscribe = undefined; this.state.busy = false }

	async loadWorkflows(signal?: AbortSignal): Promise<readonly WorkflowSummary[]> {
		if (!this.api?.contentRead) return []
		const result = await this.api.contentRead(this.descriptor.provider, { connectionId: this.descriptor.connectionId }, signal ?? new AbortController().signal) as { workflows?: readonly WorkflowSummary[] }
		return result.workflows ?? []
	}

	async selectTarget(reference: string, sourceKind: 'saved' | 'own-draft' = 'saved', name?: string, signal?: AbortSignal): Promise<WorkflowTarget> {
		if (this.state.busy) throw new Error('Cannot change workflow target while assistant is working.')
		if (!this.api?.contentRead) throw new Error('Native providers API is unavailable.')
		const result = await this.api.contentRead(this.descriptor.provider, {
			connectionId: this.descriptor.connectionId,
			reference,
			sourceKind,
		}, signal ?? new AbortController().signal) as {
			copySource?: { reference: string; kind: string; revision: string }
			resource?: { revision: string }
			definition?: { summary?: string; name?: string }
		}
		const revision = result.copySource?.revision ?? result.resource?.revision ?? ''
		const targetName = name || result.definition?.summary || result.definition?.name || reference
		const target: WorkflowTarget = {
			connectionId: this.descriptor.connectionId,
			provider: this.descriptor.provider,
			reference,
			sourceKind,
			name: targetName,
			revision,
			copySource: result.copySource,
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
