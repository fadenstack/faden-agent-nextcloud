import { createApp, h, markRaw, shallowReactive, watch } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import { subscribe } from '@nextcloud/event-bus'
import { createRuntime, type Runtime } from './agent/runtime.ts'
import type { Identity } from './integrations/nextcloud/types.ts'
import { errorMessage } from './integrations/nextcloud/files.ts'
import AgentDrawer from './components/AgentDrawer.vue'
import HeaderButton from './components/HeaderButton.vue'
import AssistantActivity from './components/AssistantActivity.vue'
import mascot from '../img/app.svg?raw'
import { installViewerIntegration, type ViewedDocument } from './integrations/nextcloud/viewer.ts'
import { currentFileSelection, observeFileSelection } from './workspace/fileSelection.ts'
import { installEngineSurface } from './workspace/engineSurface.ts'
import EngineWorkspacePane from './components/EngineWorkspacePane.vue'

const state = shallowReactive({ open: false, loading: false, error: '', runtime: undefined as Runtime | undefined, fileId: undefined as number | undefined, documentName: undefined as string | undefined, selectionEpoch: 0 })
const icon = mascot.replaceAll('#FFFFFF', 'currentColor')
let sidebarSelection: (() => number | undefined) | undefined
let closeFileDetails: (() => void) | undefined

async function openDrawer(fileId?: number, documentName?: string) {
	state.documentName = documentName
	state.fileId = documentName ? fileId : fileId ?? currentFileSelection() ?? (document.querySelector('#fadenstack-native-frame') ? undefined : sidebarSelection?.()) ?? state.fileId
	state.selectionEpoch++
	state.open = true
	document.body.classList.add('fadenstack-agent-open')
	closeFileDetails?.()
	if (state.runtime) { await state.runtime.verify(); return }
	state.loading = true
	state.error = ''
	try {
		const config = (await axios.get<Identity>(generateUrl('/apps/fadenstack_agent/config'))).data
		state.runtime = markRaw(createRuntime(config))
	} catch (error) { state.error = errorMessage(error) } finally { state.loading = false }
}
function closeDrawer() { state.open = false; document.body.classList.remove('fadenstack-agent-open'); state.runtime?.state.host?.stop() }
function minimiseDrawer() { state.open = false; document.body.classList.remove('fadenstack-agent-open') }
function showDrawer() { state.open = true; document.body.classList.add('fadenstack-agent-open') }

async function registerFilesIntegration() {
	const major = Number((window as unknown as { OC: { config: { version: string } } }).OC.config.version.split('.')[0])
	if (major >= 33) {
		const { getSidebar, registerFileAction } = await import('@nextcloud/files')
		sidebarSelection = () => getSidebar().isOpen ? getSidebar().node?.fileid ?? undefined : undefined
		closeFileDetails = () => { if (getSidebar().isOpen) getSidebar().close() }
		registerFileAction({
			id: 'fadenstack_agent',
			displayName: () => t('fadenstack_agent', 'Ask Fadenstack'),
			iconSvgInline: () => icon,
			order: 60,
			enabled: ({ nodes }) => nodes.length === 1,
			exec: async ({ nodes }) => { await openDrawer(nodes[0].fileid ?? undefined); return true },
		})
		subscribe('files:sidebar:opened', () => {
			const id = getSidebar().node?.fileid
			if (id && !state.documentName) state.fileId = id
			if (state.open) closeFileDetails?.()
		})
	} else {
		const { FileAction, registerFileAction } = await import('@nextcloud/files-legacy')
		registerFileAction(new FileAction({
			id: 'fadenstack_agent',
			displayName: () => t('fadenstack_agent', 'Ask Fadenstack'),
			iconSvgInline: () => icon,
			order: 60,
			enabled: nodes => nodes.length === 1,
			exec: async node => { await openDrawer(node.fileid ?? undefined); return true },
		}))
	}
}

function mount() {
	if (window.parent !== window && window.frameElement?.id === 'fadenstack-native-frame') {
		document.body.classList.add('fadenstack-workspace-child')
		return
	}
	if (document.getElementById('fadenstack-agent-drawer')) return
	try {
		const surface = installEngineSurface(document)
		if (surface) createApp(EngineWorkspacePane, { surface: markRaw(surface) }).mount(document.getElementById('fadenstack-engine-surface')!)
	} catch { state.error = 'The native workflow workspace is unavailable. Reload before continuing.' }
	const root = document.createElement('div')
	root.id = 'fadenstack-agent-drawer'
	document.body.appendChild(root)
	createApp(AgentDrawer, { state, onClose: closeDrawer }).mount(root)
	const activity = document.createElement('div')
	activity.id = 'fadenstack-agent-activity'
	document.body.append(activity)
	createApp({ setup: () => () => h(AssistantActivity, { runtime: state.runtime, open: state.open, onShow: showDrawer, onMinimise: minimiseDrawer }) }).mount(activity)
	let locked: HTMLElement | undefined
	let wasInert = false
	watch(() => state.runtime?.workspace.state.locked, value => {
		if (locked) { locked.inert = wasInert; locked = undefined }
		if (value) {
			const target = document.querySelector<HTMLElement>('#fadenstack-native-workspace') ?? document.querySelector<HTMLElement>('#app-content-vue, #app-content')
			if (target && !target.contains(root) && !target.contains(activity)) { locked = target; wasInert = target.inert; target.inert = true }
		}
	})
	const header = document.querySelector('#header .header-end, #header .header-right')
	if (header) {
		const button = document.createElement('div')
		button.id = 'fadenstack-agent-header'
		header.prepend(button)
		createApp(HeaderButton, { state, onToggle: () => { if (state.open) closeDrawer(); else void openDrawer() } }).mount(button)
	}
	const stopSelection = observeFileSelection(document, id => { state.fileId = id }, () => !!state.documentName || !!state.runtime?.state.host?.chat.isBusy)
	const disconnectViewer = installViewerIntegration({
		drawer: root,
		activity,
		state,
		toggle: document => { if (state.open) closeDrawer(); else void openDrawer(document?.fileId, document?.name) },
		changed: (document?: ViewedDocument) => {
			if (document) { state.documentName = document.name; state.fileId = document.fileId } else if (state.documentName) { state.documentName = undefined; state.fileId = currentFileSelection() }
			state.selectionEpoch++
		},
	})
	window.addEventListener('pagehide', () => { disconnectViewer(); stopSelection() }, { once: true })
	subscribe('files:node:updated', node => {
		if (node && typeof node === 'object' && 'fileid' in node && node.fileid === state.fileId && !state.runtime?.state.host?.chat.isBusy) state.selectionEpoch++
	})

	// Nextcloud navigation entries are links. Intercept this app's entry only;
	// modified clicks retain normal new-tab behavior through the route fallback.
	document.addEventListener('click', event => {
		if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return
		const link = event.target instanceof Element ? event.target.closest<HTMLAnchorElement>('a[href]') : null
		if (!link || new URL(link.href).origin !== location.origin || !/\/apps\/fadenstack_agent\/?$/.test(new URL(link.href).pathname)) return
		event.preventDefault()
		event.stopPropagation()
		// Use the native app-menu toggle to dismiss its popup, when it is open.
		document.querySelector<HTMLButtonElement>('.app-menu__waffle[aria-expanded="true"]')?.click()
		if (state.open) closeDrawer(); else void openDrawer()
	}, true)
	void registerFilesIntegration().catch(error => { state.error = errorMessage(error) })
	if (new URLSearchParams(location.search).has('fadenstack-agent')) void openDrawer()
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, { once: true }); else mount()
