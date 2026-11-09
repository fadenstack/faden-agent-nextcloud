import { shallowReactive } from 'vue'
import type { NativeView } from './nativeView.ts'

export interface EngineSurfaceDescriptor { connectionId: string; provider: 'windmill' | 'n8n'; label: string; url: string; origin: string; revision: string }

export function engineSurfaceDescriptor(value: unknown): EngineSurfaceDescriptor {
	if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('Invalid native workspace.')
	const v = value as Record<string, unknown>
	if (Object.keys(v).length !== 6 || Object.keys(v).some(k => !['connectionId', 'provider', 'label', 'url', 'origin', 'revision'].includes(k))
		|| typeof v.connectionId !== 'string' || !/^[a-z][a-z0-9_-]{0,31}$/.test(v.connectionId)
		|| !['windmill', 'n8n'].includes(String(v.provider)) || typeof v.label !== 'string' || !v.label || v.label.length > 160
		|| typeof v.url !== 'string' || v.url.length > 2048 || typeof v.origin !== 'string'
		|| typeof v.revision !== 'string' || !/^[a-f0-9]{64}$/.test(v.revision)) throw new Error('Invalid native workspace.')
	const url = new URL(v.url)
	const loopback = /^127(?:\.(?:0|[1-9][0-9]{0,2})){3}$/.test(url.hostname) && url.hostname.split('.').every(part => Number(part) <= 255)
	if (url.origin !== v.origin || url.hostname === new URL(location.href).hostname || url.username || url.password || url.hash || (url.protocol !== 'https:'
		&& !(url.protocol === 'http:' && (url.hostname === 'localhost' || loopback)))) throw new Error('Invalid native workspace destination.')
	return Object.freeze({ ...v }) as unknown as EngineSurfaceDescriptor
}

/** The editor is opaque. A human pause is a handoff, never evidence of cleanliness. */
export class EngineSurface {

	readonly state = shallowReactive({ paused: false, busy: false, generation: 0, epoch: 0 })
	#unsubscribe: (() => void) | undefined
	constructor(readonly descriptor: EngineSurfaceDescriptor) {}

	loaded(): void { this.state.epoch++ }

	pause(): void { if (!this.state.busy) { this.state.paused = true; this.state.epoch++ } }

	resume(): void { if (!this.state.busy) { this.state.paused = false; this.state.epoch++ } }

	/** Explicit user acknowledgement only; never called by an agent or receipt. */
	reloadAcknowledged(): void {
		if (this.state.busy) return
		this.state.paused = false
		this.state.generation++
		this.state.epoch++
	}

	snapshot(): NativeView {
		return {
			app: this.descriptor.provider,
			key: 'engine-workspace:' + this.descriptor.connectionId + ':' + this.descriptor.revision,
			epoch: this.state.epoch,
			editState: 'unknown',
			...(this.state.paused ? { handoff: 'paused-engine-editor' as const } : {}),
			reason: 'The native workflow editor may contain unsaved edits. Pause the editor for agent changes, or save/cancel your edits. Pausing retains the editor; it does not claim that its content is saved.',
		}
	}

	bind(chat: { readonly isBusy: boolean; subscribe(listener: () => void): () => void }): void {
		this.#unsubscribe?.()
		const update = () => { this.state.busy = chat.isBusy }
		this.#unsubscribe = chat.subscribe(update)
		update()
	}

	unbind(): void { this.#unsubscribe?.(); this.#unsubscribe = undefined; this.state.busy = false }

}

const surfaces = new WeakMap<Document, EngineSurface>()

export function installEngineSurface(page: Document): EngineSurface | undefined {
	if (!/\/apps\/fadenstack_agent\/engines\/workspace\/[a-z][a-z0-9_-]{0,31}\/?$/.test(page.location.pathname)) return
	const root = page.querySelector<HTMLElement>('#fadenstack-engine-surface')
	if (!root) return
	const surface = new EngineSurface(engineSurfaceDescriptor(JSON.parse(root.dataset.workspace ?? 'null')))
	page.body.classList.add('fadenstack-engine-workspace-page')
	surfaces.set(page, surface)
	return surface
}
export function currentEngineSurface(page: Document): EngineSurface | undefined { return surfaces.get(page) }
