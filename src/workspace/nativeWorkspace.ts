import { generateUrl } from '@nextcloud/router'
import { NativeViewGuard, type NativeView } from './nativeView.ts'
import type { WriteResult } from '../integrations/nextcloud/types.ts'
import type { ViewOutcome } from './coordinator.ts'

const APPS = ['files', 'calendar', 'tasks', 'contacts'] as const
type NativeApp = typeof APPS[number]

/** Own only the native child. Original DOM, parent chat and token memory remain mounted. */
export class NativeWorkspace {

	readonly main: NativeViewGuard
	#frame: HTMLIFrameElement | undefined
	#child: NativeViewGuard | undefined
	#root: HTMLElement | undefined
	#app: NativeApp | undefined
	#loads = 0
	readonly #hidden: { element: HTMLElement; hidden: boolean; inert: boolean }[] = []
	constructor(readonly page: Document = document) { this.main = new NativeViewGuard(page) }

	snapshot(): NativeView {
		if (!this.#frame) return this.main.snapshot()
		try {
			const child = this.#frame.contentDocument
			if (!child || child.location.origin !== this.page.location.origin || child.readyState !== 'complete' || !this.#child) return this.unknown()
			const view = this.#child.snapshot()
			if (view.app !== this.#app || [...this.page.querySelectorAll('[role="dialog"]')].some(node => !node.closest('#fadenstack-agent-drawer, #fadenstack-agent-activity') && !node.querySelector('.fadenstack-assistant-details'))) return this.unknown()
			return { ...view, key: 'workspace:' + view.key, epoch: this.#loads * 1000000 + view.epoch }
		} catch { return this.unknown() }
	}

	private unknown(): NativeView {
		return {
			app: this.#app ?? 'unknown',
			key: 'workspace:unavailable',
			epoch: this.#loads * 1000000,
			editState: 'unknown',
			reason: 'The native view is loading or inaccessible. Finish or cancel native edits before continuing.',
		}
	}

	async prepare(app: string): Promise<void> {
		if (!APPS.includes(app as NativeApp)) return
		const current = this.snapshot()
		if (current.app !== app) return // An off-screen change retains an honest not-visible receipt.
		if (current.editState !== 'clean') throw new Error(current.reason)
		if (this.#frame) return
		this.#app = app as NativeApp
		const original = new URL(this.page.location.href)
		original.searchParams.delete('fadenstack-agent')
		original.searchParams.set('fadenstack-workspace-child', '1')
		const root = this.page.createElement('section')
		root.id = 'fadenstack-native-workspace'
		root.inert = true
		root.setAttribute('aria-label', 'Native ' + app + ' workspace')
		const toolbar = this.page.createElement('div')
		toolbar.className = 'fadenstack-native-workspace-toolbar'
		const label = this.page.createElement('span')
		label.textContent = app[0].toUpperCase() + app.slice(1)
		toolbar.append(label)
		const refresh = this.page.createElement('button')
		refresh.type = 'button'
		refresh.textContent = 'I saved or cancelled my edits · refresh view'
		refresh.addEventListener('click', () => { void this.refreshAcknowledged().catch(error => { label.textContent = error instanceof Error ? error.message : 'The native view could not be refreshed.' }) })
		toolbar.append(refresh)
		const frame = this.page.createElement('iframe')
		frame.id = 'fadenstack-native-frame'
		frame.title = 'Native ' + app
		frame.addEventListener('load', () => {
			this.#loads++
			this.#child?.dispose()
			this.#child = undefined
			try {
				const child = frame.contentDocument
				if (child && child.location.origin === this.page.location.origin) this.#child = new NativeViewGuard(child)
			} catch { /* Cross-origin/native framing denial stays unknown. */ }
		})
		frame.src = original.href
		root.append(toolbar, frame)
		this.#frame = frame
		this.#root = root
		for (const element of this.page.querySelectorAll<HTMLElement>('#content, #content-vue')) {
			if (element.querySelector('#fadenstack-agent-drawer')) continue
			this.#hidden.push({ element, hidden: element.hidden, inert: element.inert })
			element.hidden = true
			element.inert = true
		}
		this.page.body.append(root)
		try { await this.ready(AbortSignal.timeout(15000)) } finally { root.inert = false }
	}

	private async ready(signal: AbortSignal): Promise<void> {
		while (true) {
			signal.throwIfAborted()
			if (this.#frame?.contentDocument?.querySelector('#app-content, #app-content-vue') && this.snapshot().editState === 'clean') return
			await new Promise<void>((resolve, reject) => {
				const done = () => { clearTimeout(timer); signal.removeEventListener('abort', aborted); resolve() }
				const aborted = () => { clearTimeout(timer); signal.removeEventListener('abort', aborted); reject(signal.reason) }
				const timer = setTimeout(done, 50)
				signal.addEventListener('abort', aborted, { once: true })
			})
		}
	}

	async refresh(result: WriteResult, signal: AbortSignal): Promise<ViewOutcome> {
		if (!result.provider || !this.#frame || this.#app !== result.provider.app) return 'refresh_required'
		if (result.provider.app === 'files' && !/^[1-9][0-9]{0,14}$/.test(result.provider.collectionId)) return 'refresh_required'
		if (this.snapshot().editState !== 'clean') return 'refresh_required'
		const target = new URL(this.#frame.contentDocument!.location.href)
		if (result.provider.app === 'tasks' && result.provider.collectionId) target.href = new URL(generateUrl('/apps/tasks/calendars/' + encodeURIComponent(result.provider.collectionId)), this.page.location.href).href
		if (result.provider.app === 'files' && /^[1-9][0-9]{0,14}$/.test(result.provider.collectionId)) target.href = new URL(generateUrl('/f/' + result.provider.collectionId), this.page.location.href).href
		if (result.provider.app === 'calendar' && result.provider.date && /^\d{4}-\d{2}-\d{2}$/.test(result.provider.date)) target.href = new URL(generateUrl('/apps/calendar/dayGridMonth/' + result.provider.date), this.page.location.href).href
		target.searchParams.set('fadenstack-workspace-child', '1')
		await this.reload(target, signal)
		return 'refreshed'
	}

	private async reload(target: URL, signal: AbortSignal): Promise<void> {
		const frame = this.#frame
		if (!frame) throw new Error('The native workspace is unavailable.')
		const oldDocument = frame.contentDocument
		frame.src = target.href
		while (frame.contentDocument === oldDocument) {
			signal.throwIfAborted()
			await new Promise(resolve => setTimeout(resolve, 25))
		}
		await this.ready(signal)
	}

	async refreshAcknowledged(): Promise<void> {
		if (!this.#frame) return
		this.#child?.acknowledgeNavigation()
		if (this.snapshot().editState !== 'clean') throw new Error('Close the native editor after saving or cancelling before refreshing.')
		await this.reload(new URL(this.#frame.contentDocument!.location.href), AbortSignal.timeout(15000))
	}

	dispose(): void {
		this.main.dispose()
		this.#child?.dispose()
		this.#root?.remove()
		for (const saved of this.#hidden) { saved.element.hidden = saved.hidden; saved.element.inert = saved.inert }
		this.#hidden.length = 0
		this.#frame = undefined
	}

}
