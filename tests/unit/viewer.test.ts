import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick, shallowReactive } from 'vue'
import { installViewerIntegration, viewedDocument } from '../../src/integrations/nextcloud/viewer.ts'

const events = vi.hoisted(() => ({ emit: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => events)
// Keep this test about the bridge's DOM/focus lifecycle, independent of NcButton.
vi.mock('../../src/components/HeaderButton.vue', () => ({ default: {
	props: ['state'], emits: ['toggle'],
	template: '<button :aria-expanded="state.open" @click="$emit(\'toggle\')">Fadenstack Agent</button>',
} }))
function modal() {
	const viewer = document.createElement('div')
	viewer.id = 'viewer'
	viewer.className = 'modal-mask viewer'
	viewer.setAttribute('role', 'dialog')
	viewer.innerHTML = '<div class="modal-header"><h2 class="modal-header__name">saved.md</h2><div class="icons-menu"><button>Close</button></div></div>'
	return viewer
}
afterEach(() => { document.body.replaceChildren(); document.body.className = ''; events.emit.mockClear() })
describe('Nextcloud fullscreen Viewer bridge', () => {
	it('resolves the displayed document, not the selected file behind the editor', () => {
		const viewer = modal()
		history.replaceState(null, '', '/index.php/apps/files/files/317?openfile=true')
		expect(viewedDocument(viewer)).toEqual({ fileId: 317, name: 'saved.md' })
		history.replaceState(null, '', '/index.php/apps/files/?openfile=99')
		expect(viewedDocument(viewer)?.fileId).toBe(99)
		history.replaceState(null, '', '/index.php/apps/files/?openfile=true')
		expect(viewedDocument(viewer)?.fileId).toBeUndefined()
	})
	it('mounts one titlebar toggle, joins the modal focus trap, and returns the drawer on close', async () => {
		history.replaceState(null, '', '/index.php/apps/files/files/317?openfile=true')
		const drawer = document.createElement('div')
		drawer.id = 'fadenstack-agent-drawer'
		document.body.append(drawer)
		const state = shallowReactive({ open: false })
		const toggle = vi.fn(() => { state.open = !state.open })
		const changed = vi.fn()
		const dispose = installViewerIntegration({ drawer, state, toggle, changed })
		try {
			const viewer = modal()
			document.body.append(viewer)
			await vi.waitFor(() => expect(viewer.querySelectorAll('.fadenstack-viewer-button')).toHaveLength(1))
			expect(drawer.parentElement).toBe(viewer)
			expect(changed).toHaveBeenLastCalledWith({ name: 'saved.md', fileId: 317 })
			viewer.querySelector<HTMLButtonElement>('.fadenstack-viewer-button button')!.click()
			await nextTick()
			await vi.waitFor(() => expect(events.emit).toHaveBeenCalledWith('viewer:trapElements:changed', drawer))
			expect(toggle).toHaveBeenCalledWith({ name: 'saved.md', fileId: 317 })
			state.open = false
			await nextTick()
			state.open = true
			await nextTick()
			expect(events.emit).toHaveBeenCalledTimes(1)
			viewer.querySelector('.modal-header__name')!.textContent = 'other.md'
			await vi.waitFor(() => expect(changed).toHaveBeenLastCalledWith({ name: 'other.md', fileId: 317 }))
			expect(viewer.querySelectorAll('.fadenstack-viewer-button')).toHaveLength(1)
			viewer.remove()
			await vi.waitFor(() => expect(drawer.parentElement).toBe(document.body))
			expect(document.body.classList.contains('fadenstack-agent-in-viewer')).toBe(false)
			expect(changed).toHaveBeenLastCalledWith(undefined)
		} finally { dispose() }
	})
})
