import { createApp, watch, type App } from 'vue'
import { emit } from '@nextcloud/event-bus'
import HeaderButton from '../../components/HeaderButton.vue'

export interface ViewedDocument { fileId?: number; name: string }

/**
 * Viewer 31–35 has no header-action registration API. Use its visible modal
 * header and public focus-trap event, never private Vue instances or editor data.
 */
export function viewedDocument(viewer: HTMLElement): ViewedDocument | undefined {
	const name = viewer.querySelector('.modal-header__name')?.textContent?.trim()
	if (!name) return undefined
	const query = new URLSearchParams(location.search)
	const id = Number(/^\d+$/.test(query.get('openfile') || '')
		? query.get('openfile')
		: location.pathname.match(/\/apps\/files\/(?:files|personal)\/(\d+)(?:\/|$)/)?.[1])
	return { name, fileId: Number.isSafeInteger(id) && id > 0 ? id : undefined }
}

export function installViewerIntegration(options: {
	drawer: HTMLElement
	activity?: HTMLElement
	state: { open: boolean }
	toggle(document?: ViewedDocument): void
	changed(document?: ViewedDocument): void
}) {
	let button: HTMLElement | undefined
	let app: App | undefined
	let owner: HTMLElement | undefined
	let documentKey = ''
	let trapRegistered = false
	let activityTrapRegistered = false
	function sync() {
		// Only Nextcloud's actual modal, outside our rendered Markdown, is eligible.
		const viewer = [...document.querySelectorAll<HTMLElement>('#viewer.modal-mask.viewer[role="dialog"]')]
			.find(element => !element.closest('#fadenstack-agent-drawer'))
		const header = viewer?.querySelector<HTMLElement>('.modal-header .icons-menu')
		if (owner !== viewer || (button && !button.isConnected)) {
			app?.unmount()
			button?.remove()
			button = undefined
			app = undefined
			owner = viewer
			trapRegistered = false
			activityTrapRegistered = false
		}
		if (viewer && header && !button) {
			button = document.createElement('div')
			button.className = 'fadenstack-viewer-button'
			header.prepend(button)
			app = createApp(HeaderButton, { state: options.state, onToggle: () => options.toggle(viewedDocument(viewer)) })
			app.mount(button)
		}
		const parent = viewer ?? document.body
		if (options.drawer.parentElement !== parent) parent.append(options.drawer)
		if (options.activity && options.activity.parentElement !== parent) parent.append(options.activity)
		if (viewer) {
			// The modal may have made its former siblings inert before relocation.
			options.drawer.removeAttribute('inert')
			options.drawer.removeAttribute('aria-hidden')
			options.activity?.removeAttribute('inert')
			options.activity?.removeAttribute('aria-hidden')
			if (options.state.open && !trapRegistered) {
				emit('viewer:trapElements:changed', options.drawer)
				trapRegistered = true
			}
			if (options.activity && options.activity.childElementCount > 0 && !activityTrapRegistered) {
				emit('viewer:trapElements:changed', options.activity)
				activityTrapRegistered = true
			}
		}
		const inViewer = !!viewer
		if (document.body.classList.contains('fadenstack-agent-in-viewer') !== inViewer) document.body.classList.toggle('fadenstack-agent-in-viewer', inViewer)
		const current = viewer ? viewedDocument(viewer) : undefined
		const key = current ? `${current.fileId}:${current.name}` : ''
		if (key !== documentKey) { documentKey = key; options.changed(current) }
	}
	const observer = new MutationObserver(sync)
	const stopWatchingOpen = watch(() => options.state.open, sync, { flush: 'post' })
	observer.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['class', 'data-handler'] })
	document.addEventListener('fullscreenchange', sync)
	sync()
	return () => {
		observer.disconnect()
		stopWatchingOpen()
		document.removeEventListener('fullscreenchange', sync)
		app?.unmount()
		button?.remove()
		document.body.append(options.drawer)
		if (options.activity) document.body.append(options.activity)
		document.body.classList.remove('fadenstack-agent-in-viewer')
	}
}
