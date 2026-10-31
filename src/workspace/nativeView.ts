export type EditState = 'clean' | 'dirty' | 'unknown'
export interface NativeView { app: string; key: string; epoch: number; editState: EditState; reason: string }

/** A conservative UI latch. Blur or closing a form never establishes that edits were saved. */
export class NativeViewGuard {

	#dirty = false
	#epoch = 0
	#key = ''
	readonly #edit = (event: Event) => {
		const ElementType = this.page.defaultView?.Element
		const element = ElementType && event.target instanceof ElementType ? event.target as Element : undefined
		if (!element || element.closest('#fadenstack-agent-drawer, #fadenstack-agent-activity, #fadenstack-agent-header')) return
		if (element.matches('input[type="checkbox"]') && element.closest('.files-list__row, .files-list__thead')) return
		if (element.closest('#app-content, #app-content-vue, #viewer')) this.#dirty = true
	}

	constructor(readonly page: Document = document) {
		for (const name of ['beforeinput', 'input', 'change', 'compositionstart', 'drop', 'submit']) page.addEventListener(name, this.#edit, true)
	}

	snapshot(): NativeView {
		const url = new URL(this.page.location.href)
		const app = url.pathname.match(/\/apps\/([a-z0-9_]+)(?:\/|$)/)?.[1] ?? 'unknown'
		const key = `${url.pathname}${url.search}${url.hash}`
		if (key !== this.#key) { this.#key = key; this.#epoch++ }
		const viewer = this.page.querySelector('#viewer.modal-mask.viewer[role="dialog"]')
		const dialog = [...this.page.querySelectorAll('[role="dialog"]')].some(element => !element.closest('#fadenstack-agent-drawer, #fadenstack-agent-activity') && !element.querySelector('.fadenstack-assistant-details'))
		const editor = [...this.page.querySelectorAll('#app-content [contenteditable]:not([contenteditable="false"]), #app-content-vue [contenteditable]:not([contenteditable="false"]), #app-content textarea, #app-content-vue textarea')]
			.some(element => !element.closest('#fadenstack-agent-drawer, #fadenstack-agent-activity'))
		const nativeEditor = ['calendar', 'tasks', 'contacts'].includes(app) && (
			/\/(?:edit|new|import)(?:\/|$)/.test(url.pathname)
			|| (app === 'tasks' && /\/tasks\/[^/]+/.test(url.pathname.split('/apps/tasks')[1] ?? ''))
			|| (app === 'contacts' && [...this.page.querySelectorAll('.contact-details, #contact-details, .contact-details-wrapper')]
				.some(node => !node.closest('.contact-details-wrapper-read-only, .display-contact-details')))
			|| [...this.page.querySelectorAll<HTMLInputElement>('#app-content input, #app-content-vue input')].some(input =>
				!input.closest('#fadenstack-agent-drawer, #fadenstack-agent-activity') && !['checkbox', 'radio', 'hidden', 'button', 'submit', 'search'].includes(input.type) && input.value.trim() !== ''))
		const ready = !!this.page.querySelector('#app-content, #app-content-vue') && !this.page.querySelector('#app-content [aria-busy="true"], #app-content-vue [aria-busy="true"]')
		const clean = !viewer && !dialog && !editor && !nativeEditor && (app === 'files'
			? !!this.page.querySelector('.files-list, .files-list__row')
			: ['calendar', 'tasks', 'contacts'].includes(app) && ready)
		return { app, key, epoch: this.#epoch, editState: this.#dirty ? 'dirty' : clean ? 'clean' : 'unknown', reason: this.#dirty ? 'Finish or cancel your edits before asking the assistant to change this view.' : app === 'files' && editor ? 'A Files preview or folder-description editor is open. Save or close it, or open a folder without an editor before asking the assistant to make a change.' : 'The assistant cannot confirm that this editor has no unsaved changes.' }
	}

	/** Explicit acknowledgement is used only by a user-requested refresh/handoff, never by an agent. */
	acknowledgeNavigation(): void { this.#dirty = false; this.#epoch++ }

	dispose(): void {
		for (const name of ['beforeinput', 'input', 'change', 'compositionstart', 'drop', 'submit']) this.page.removeEventListener(name, this.#edit, true)
	}

}
