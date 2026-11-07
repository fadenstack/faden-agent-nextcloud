import { generateUrl } from '@nextcloud/router'
import { NativeViewGuard, type NativeView } from './nativeView.ts'
import type { WriteResult } from '../integrations/nextcloud/types.ts'
import type { ViewOutcome } from './coordinator.ts'

const APPS = ['files', 'calendar', 'tasks', 'contacts', 'tables', 'deck', 'mail', 'spreed', 'collectives', 'forms', 'notes', 'bookmarks'] as const
type NativeApp = typeof APPS[number]

/** Own only the native child. Original DOM, parent chat and token memory remain mounted. */
export class NativeWorkspace {

	readonly main: NativeViewGuard
	#frame: HTMLIFrameElement | undefined
	#child: NativeViewGuard | undefined
	#root: HTMLElement | undefined
	#app: NativeApp | undefined
	#loads = 0
	#refreshes = 0
	readonly #hidden: { element: HTMLElement; hidden: boolean; inert: boolean; masked: boolean }[] = []
	constructor(readonly page: Document = document) { this.main = new NativeViewGuard(page) }

	snapshot(): NativeView {
		if (!this.#frame) return this.main.snapshot()
		try {
			if (this.#app === 'mail' && this.main.snapshot().editState !== 'clean') return this.unknown()
			const child = this.#frame.contentDocument
			if (!child || child.location.origin !== this.page.location.origin || child.readyState !== 'complete' || !this.#child) return this.unknown()
			const view = this.#child.snapshot()
			if (view.app !== this.#app || [...this.page.querySelectorAll('[role="dialog"]')].some(node => !this.#hidden.some(saved => saved.element.contains(node)) && !node.closest('#fadenstack-agent-drawer, #fadenstack-agent-activity') && !node.querySelector('.fadenstack-assistant-details'))) return this.unknown()
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
		// Talk owns a single native conversation session. A hidden parent plus a
		// child frame would compete for it and trigger its duplicate-session UI.
		if (app === 'spreed') return
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
		label.textContent = app === 'spreed' ? 'Talk' : app[0].toUpperCase() + app.slice(1)
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
			this.#hidden.push({ element, hidden: element.hidden, inert: element.inert, masked: element.classList.contains('fadenstack-native-masked') })
			element.hidden = true
			element.inert = true
			element.classList.add('fadenstack-native-masked')
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
		if (result.provider?.app === 'spreed') return this.observeTalk(result, signal)
		if (!result.provider || !this.#frame || this.#app !== result.provider.app) return 'refresh_required'
		if (result.provider.app === 'files' && !/^[1-9][0-9]{0,14}$/.test(result.provider.collectionId)) return 'refresh_required'
		if (this.snapshot().editState !== 'clean') return 'refresh_required'
		const target = new URL(this.#frame.contentDocument!.location.href)
		if (result.provider.app === 'tasks' && result.provider.collectionId) target.href = new URL(generateUrl('/apps/tasks/calendars/' + encodeURIComponent(result.provider.collectionId)), this.page.location.href).href
		if (result.provider.app === 'files' && /^[1-9][0-9]{0,14}$/.test(result.provider.collectionId)) target.href = new URL(generateUrl('/f/' + result.provider.collectionId), this.page.location.href).href
		if (result.provider.app === 'calendar' && result.provider.date && /^\d{4}-\d{2}-\d{2}$/.test(result.provider.date)) target.href = new URL(generateUrl('/apps/calendar/dayGridMonth/' + result.provider.date), this.page.location.href).href
		if (['tables', 'deck'].includes(result.provider.app)) {
			if (!/^[1-9][0-9]{0,14}$/.test(result.provider.collectionId)) return 'refresh_required'
			// Keep the current route, sort and filter state; never reload another object
			// after navigation. The coordinator already checked the approval's route epoch.
			const route = target.hash + target.pathname
			const expected = result.provider.app === 'tables' ? /(?:#\/table\/|\/tables\/table\/)([1-9][0-9]*)(?:[/?]|$)/ : /(?:#\/board\/|\/deck\/board\/)([1-9][0-9]*)(?:[/?]|$)/
			if (expected.exec(route)?.[1] !== result.provider.collectionId) return 'not_visible'
		}
		if (result.provider.app === 'mail' && !/^\d+$/.test(result.provider.collectionId)) return 'refresh_required'
		if (result.provider.app === 'mail') {
			if (!Number.isSafeInteger(result.provider.mailboxId) || result.provider.mailboxId! < 1) return 'refresh_required'
			target.href = new URL(generateUrl('/apps/mail/box/' + result.provider.mailboxId), this.page.location.href).href
		}
		if (result.provider.app === 'notes') {
			if (!Number.isSafeInteger(result.file.id) || result.file.id < 1) return 'refresh_required'
			target.href = new URL(generateUrl('/apps/notes/note/' + result.file.id), this.page.location.href).href
		}
		if (result.provider.app === 'collectives') {
			const collection = target.pathname.split('/apps/collectives/')[1]?.split('/')[0]
			if (!collection?.endsWith('-' + result.provider.collectionId)) return 'not_visible'
		}
		target.searchParams.set('fadenstack-workspace-child', '1')
		await this.reload(target, signal)
		return 'refreshed'
	}

	/** Observe Talk's existing native history updates without touching its store or editor. */
	private async observeTalk(result: WriteResult, signal: AbortSignal): Promise<ViewOutcome> {
		const token = result.provider!.collectionId
		if (this.#frame || !/^[a-z0-9]{4,30}$/.test(token) || !Number.isSafeInteger(result.file.id) || result.file.id < 1) return 'refresh_required'
		const view = this.main.snapshot()
		if (view.app !== 'spreed' || !new URL(this.page.location.href).pathname.endsWith('/call/' + token)) return 'not_visible'
		if (view.editState !== 'clean') return 'refresh_required'
		const selector = '#app-content [data-message-id="' + result.file.id + '"], #app-content-vue [data-message-id="' + result.file.id + '"]'
		const visible = () => [...this.page.querySelectorAll<HTMLElement>(selector)].some(node => node.getClientRects().length > 0)
		if (visible()) return 'refreshed'
		return new Promise<ViewOutcome>((resolve, reject) => {
			const observer = new MutationObserver(() => { if (visible()) { cleanup(); resolve('refreshed') } })
			const aborted = () => { cleanup(); reject(signal.reason) }
			const cleanup = () => { observer.disconnect(); signal.removeEventListener('abort', aborted) }
			observer.observe(this.page.body, { childList: true, subtree: true })
			signal.addEventListener('abort', aborted, { once: true })
			if (signal.aborted) aborted()
		})
	}

	private async reload(target: URL, signal: AbortSignal): Promise<void> {
		const frame = this.#frame
		if (!frame) throw new Error('The native workspace is unavailable.')
		const oldDocument = frame.contentDocument
		// Tables/Deck use hash routes. Assigning the same fragment URL can be a
		// same-document navigation, leaving stale Vue state and no load event.
		// A child-only query revision forces a real document reload, retaining
		// the native path, hash and existing sort/filter query parameters.
		target.searchParams.set('fadenstack-workspace-refresh', String(++this.#refreshes))
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
		for (const saved of this.#hidden) { saved.element.hidden = saved.hidden; saved.element.inert = saved.inert; saved.element.classList.toggle('fadenstack-native-masked', saved.masked) }
		this.#hidden.length = 0
		this.#frame = undefined
	}

}
