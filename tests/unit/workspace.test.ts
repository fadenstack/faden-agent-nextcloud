import { afterEach, describe, expect, it, vi } from 'vitest'
import { NativeViewGuard, type NativeView } from '../../src/workspace/nativeView.ts'
import { WorkspaceCoordinator } from '../../src/workspace/coordinator.ts'
import { NativeWorkspace } from '../../src/workspace/nativeWorkspace.ts'

const file = { id: 12, name: 'summary.md', path: '/Project/summary.md', mime: 'text/markdown', size: 8, etag: 'after', folder: false, permissions: 31, url: '/f/12' }
const initial: NativeView = { app: 'files', key: '/apps/files/files/1', epoch: 1, editState: 'clean', reason: 'Finish editing first.' }
const guards: NativeViewGuard[] = []
afterEach(() => { guards.splice(0).forEach(guard => guard.dispose()); document.body.replaceChildren(); history.replaceState(null, '', '/') })

function nativeGuard() {
	history.replaceState(null, '', '/apps/files/files/1')
	document.body.innerHTML = '<main id="app-content-vue"><div class="files-list"><div class="files-list__row"><input type="checkbox"><input class="rename"></div></div></main><div id="fadenstack-agent-drawer"><textarea></textarea></div>'
	const guard = new NativeViewGuard()
	guards.push(guard)
	return guard
}

describe('Native editor protection', () => {
	it('recognizes only Collectives’ actual read-only renderer and disabled page title', () => {
		history.replaceState(null, '', '/apps/collectives/Synthetic-1')
		document.body.innerHTML = '<main id="app-content-vue"><div class="page-title"><input class="title" disabled></div><div class="ProseMirror" contenteditable="false">Saved page</div></main>'
		const guard = new NativeViewGuard(); guards.push(guard)
		expect(guard.snapshot().editState).toBe('clean')
		// Collectives mounts an inactive Text editor alongside its readonly renderer.
		document.querySelector('main')!.insertAdjacentHTML('beforeend', '<div class="inactive-editor" contenteditable="true" style="display:none">Native buffer</div><input type="file" data-text-el="attachment-file-input" style="display:none">')
		expect(guard.snapshot().editState).toBe('clean')
		document.querySelector('.inactive-editor')!.dispatchEvent(new Event('beforeinput', { bubbles: true }))
		expect(guard.snapshot().editState).toBe('dirty')
		guard.acknowledgeNavigation()
		document.querySelector('input')!.disabled = false
		expect(guard.snapshot().editState).toBe('unknown')
		document.querySelector('.ProseMirror')!.setAttribute('contenteditable', 'true')
		expect(guard.snapshot().editState).toBe('unknown')
	})
	it.each(['forms', 'notes', 'bookmarks'])('keeps %s inputs and editors protected while allowing a plain browse view', app => {
		history.replaceState(null, '', '/apps/' + app + '/')
		document.body.innerHTML = '<main id="app-content-vue"></main>'
		const guard = new NativeViewGuard(); guards.push(guard)
		expect(guard.snapshot().editState).toBe('clean')
		document.querySelector('main')!.insertAdjacentHTML('beforeend', '<input value="Saved or pending native control">')
		expect(guard.snapshot().editState).toBe('unknown')
		document.querySelector('input')!.dispatchEvent(new Event('input', { bubbles: true }))
		document.querySelector('input')!.remove()
		expect(guard.snapshot().editState).toBe('dirty')
	})
	it.each([['bookmarks', 'inline-search'], ['notes', 'content-list__search']])('does not treat the tested native %s search field as an editor', (app, selector) => {
		history.replaceState(null, '', '/apps/' + app + '/')
		document.body.innerHTML = `<div class="app-${app}"><main id="app-content-vue"><section class="${selector}"><input></section></main></div>`
		const guard = new NativeViewGuard(); guards.push(guard)
		document.querySelector('input')!.dispatchEvent(new Event('input', { bubbles: true }))
		expect(guard.snapshot().editState).toBe('clean')
	})
	it.each(['subject', 'recipient', 'body'])('protects Mail portal %s edits outside app content, even after its removal', async field => {
		history.replaceState(null, '', '/apps/mail/box/1')
		document.body.innerHTML = '<main id="app-content"></main><section class="floating-composer"><div class="message-composer"><input class="subject"><input type="search" class="recipient"><div class="body" contenteditable="true"></div></div></section>'
		const workspace = new NativeWorkspace()
		try {
			expect(workspace.snapshot().editState).toBe('unknown')
			await expect(workspace.prepare('mail')).rejects.toThrow('cannot confirm')
			expect(document.querySelector('iframe')).toBeNull()
			document.querySelector('.' + field)!.dispatchEvent(new Event('input', { bubbles: true }))
			expect(workspace.snapshot().editState).toBe('dirty')
			document.querySelector('.floating-composer')!.remove()
			expect(workspace.snapshot().editState).toBe('dirty')
		} finally { workspace.dispose() }
	})
	it('keeps Talk in one native session and observes its actual visible message', async () => {
		history.replaceState(null, '', '/call/abcd1234')
		document.body.innerHTML = '<main id="app-content"><div role="textbox" contenteditable="true"></div></main>'
		const workspace = new NativeWorkspace()
		try {
			await workspace.prepare('spreed')
			expect(document.querySelector('iframe')).toBeNull()
			const signal = new AbortController()
			const update = workspace.refresh({ operation: 'nc_talk_send_message', file: { ...file, id: 20 }, provider: { app: 'spreed', collectionId: 'abcd1234', filename: '20' } }, signal.signal)
			const message = document.createElement('li'); message.dataset.messageId = '20'
			vi.spyOn(message, 'getClientRects').mockReturnValue([{} as DOMRect] as unknown as DOMRectList)
			document.querySelector('main')!.append(message)
			expect(await update).toBe('refreshed')
			document.querySelector('[role="textbox"]')!.dispatchEvent(new Event('input', { bubbles: true }))
			await expect(workspace.prepare('spreed')).rejects.toThrow('Finish or cancel')
			expect(await workspace.refresh({ operation: 'nc_talk_send_message', file, provider: { app: 'spreed', collectionId: 'abcd1234', filename: '12' } }, signal.signal)).toBe('refresh_required')
		} finally { workspace.dispose() }
	})
	it('latches edits from a native child document using that document\'s event realm', () => {
		const frame = document.createElement('iframe')
		document.body.append(frame)
		const child = frame.contentDocument!
		child.body.innerHTML = '<main id="app-content"><input value="Native draft"></main>'
		const guard = new NativeViewGuard(child)
		guards.push(guard)
		child.querySelector('input')!.dispatchEvent(new child.defaultView!.Event('input', { bubbles: true }))
		expect(guard.snapshot().editState).toBe('dirty')
		child.querySelector('input')!.remove()
		expect(guard.snapshot().editState).toBe('dirty')
	})

	it('distinguishes the native Contacts read-only account card from an editable contact', () => {
		const guard = nativeGuard()
		history.replaceState(null, '', '/apps/contacts/All%20contacts/account')
		document.querySelector('#app-content-vue')!.insertAdjacentHTML('beforeend', '<div class="contact-details-wrapper contact-details-wrapper-read-only"><section class="contact-details">Account profile</section></div>')
		expect(guard.snapshot().editState).toBe('clean')
		document.querySelector('.contact-details-wrapper')!.classList.remove('contact-details-wrapper-read-only')
		expect(guard.snapshot().editState).toBe('unknown')
		document.querySelector('.contact-details-wrapper')!.classList.add('contact-details-wrapper-read-only')
		document.querySelector('.contact-details')!.insertAdjacentHTML('beforeend', '<input value="Unsaved contact">')
		expect(guard.snapshot().editState).toBe('unknown')
	})

	it('treats an inline Files description editor as unknown before any input event', () => {
		const guard = nativeGuard()
		document.querySelector('#app-content-vue')!.insertAdjacentHTML('beforeend', '<div class="editor-container"><div contenteditable="true">Inline native description</div></div>')
		expect(guard.snapshot().editState).toBe('unknown')
	})
	it('does not interpret native selection or chat typing as a native draft', () => {
		const guard = nativeGuard()
		expect(guard.snapshot().editState).toBe('clean')
		document.querySelector('input[type="checkbox"]')!.dispatchEvent(new Event('change', { bubbles: true }))
		document.querySelector('textarea')!.dispatchEvent(new Event('input', { bubbles: true }))
		expect(guard.snapshot().editState).toBe('clean')
	})
	it.each(['beforeinput', 'input', 'compositionstart', 'drop', 'submit'])('latches %s before a native edit can be lost', name => {
		const guard = nativeGuard()
		const input = document.querySelector('.rename')!
		input.dispatchEvent(new Event(name, { bubbles: true }))
		input.dispatchEvent(new Event('blur', { bubbles: true }))
		input.remove()
		expect(guard.snapshot().editState).toBe('dirty')
		// Native SPA navigation is not proof that a different app's draft has saved.
		history.replaceState(null, '', '/apps/files/files/2')
		expect(guard.snapshot().editState).toBe('dirty')
	})
	it('requires an explicit acknowledgement before releasing a dirty latch', () => {
		const guard = nativeGuard()
		document.querySelector('.rename')!.dispatchEvent(new Event('beforeinput', { bubbles: true }))
		const prior = guard.snapshot()
		guard.acknowledgeNavigation()
		expect(guard.snapshot()).toMatchObject({ editState: 'clean', epoch: prior.epoch + 1 })
	})
	it('treats a native editor and an unfamiliar app as unknown', () => {
		const guard = nativeGuard()
		const viewer = document.createElement('div')
		viewer.id = 'viewer'; viewer.className = 'modal-mask viewer'; viewer.setAttribute('role', 'dialog')
		document.body.append(viewer)
		expect(guard.snapshot().editState).toBe('unknown')
		viewer.remove()
		history.replaceState(null, '', '/apps/unrecognized')
		expect(guard.snapshot()).toMatchObject({ app: 'unrecognized', editState: 'unknown' })
	})
	it.each(['calendar', 'tasks', 'contacts'])('recognizes a pristine %s browse view and refuses a native editor', app => {
		const guard = nativeGuard()
		history.replaceState(null, '', '/apps/' + app + '/')
		expect(guard.snapshot()).toMatchObject({ app, editState: 'clean' })
		document.querySelector('#app-content-vue')!.insertAdjacentHTML('beforeend', '<textarea>Native draft</textarea>')
		expect(guard.snapshot().editState).toBe('unknown')
	})
	it('does not mistake its own native Details dialog for an external draft', () => {
		const guard = nativeGuard()
		document.body.insertAdjacentHTML('beforeend', '<div role="dialog"><div class="fadenstack-assistant-details"></div></div>')
		expect(guard.snapshot().editState).toBe('clean')
	})
})

function coordinator() {
	let view = { ...initial }
	const refresh = vi.fn(async () => 'refreshed' as const)
	const guard = { snapshot: () => ({ ...view }), dispose: vi.fn() }
	return { workspace: new WorkspaceCoordinator({ guard, refresh }), refresh, guard, view: (next: Partial<NativeView>) => { view = { ...view, ...next } } }
}

describe('Change and refresh coordination', () => {
	it('does not publish a native update or refresh after journal-only revision verification', async () => {
		const { workspace, refresh } = coordinator()
		const binding = workspace.prepare('files')
		const generation = workspace.begin('verify', binding)
		expect(await workspace.committed('verify', generation, binding, { operation: 'revalidate', file }, new AbortController().signal)).toMatchObject({ outcome: 'committed', operation: 'revalidate', view: 'unchanged' })
		expect(refresh).not.toHaveBeenCalled()
		expect(workspace.state.locked).toBe(false)
	})
	it('blocks a dirty view both before proposal and after an approval wait', () => {
		const { workspace, view } = coordinator()
		const binding = workspace.prepare('files')
		view({ editState: 'dirty' })
		expect(() => workspace.prepare('files')).toThrow('Finish editing')
		expect(() => workspace.begin('call', binding)).toThrow('Finish editing')
		expect(workspace.state.locked).toBe(false)
	})
	it('refuses a changed native route without dispatching a stale proposal', () => {
		const { workspace, view } = coordinator()
		const binding = workspace.prepare('files')
		view({ epoch: 2, key: '/apps/files/files/2' })
		expect(() => workspace.begin('call', binding)).toThrow('native view changed')
	})
	it('refreshes only a confirmed native save and releases the lock', async () => {
		const { workspace, refresh } = coordinator()
		const binding = workspace.prepare('files')
		const generation = workspace.begin('call', binding)
		expect(workspace.state.locked).toBe(true)
		expect(() => workspace.begin('other', binding)).toThrow('reconciled')
		const receipt = await workspace.committed('call', generation, binding, { operation: 'create', file }, new AbortController().signal)
		expect(receipt).toMatchObject({ outcome: 'committed', view: 'refreshed', resourceId: 12 })
		expect(refresh).toHaveBeenCalledOnce()
		expect(workspace.state).toMatchObject({ locked: false, phase: 'idle' })
	})
	it('retains the committed effect when refresh fails', async () => {
		const { workspace, refresh } = coordinator()
		refresh.mockRejectedValueOnce(new Error('native view failed'))
		const binding = workspace.prepare('files')
		const generation = workspace.begin('call', binding)
		expect(await workspace.committed('call', generation, binding, { operation: 'update', file }, new AbortController().signal)).toMatchObject({ outcome: 'committed', view: 'refresh_failed' })
		expect(workspace.state.locked).toBe(false)
	})
	it('does not refresh a dirty, stopped or subsequently navigated view', async () => {
		for (const kind of ['dirty', 'stopped', 'navigated'] as const) {
			const { workspace, refresh, view } = coordinator()
			const binding = workspace.prepare('files')
			const generation = workspace.begin('call', binding)
			const stop = new AbortController()
			if (kind === 'dirty') view({ editState: 'dirty' })
			if (kind === 'stopped') stop.abort()
			if (kind === 'navigated') view({ epoch: 2 })
			expect(await workspace.committed('call', generation, binding, { operation: 'update', file }, stop.signal)).toMatchObject({ outcome: 'committed', view: kind === 'navigated' ? 'not_visible' : 'refresh_required' })
			expect(refresh).not.toHaveBeenCalled()
		}
	})
	it('does not label a network failure after dispatch as cancelled or retryable', () => {
		const { workspace } = coordinator()
		const binding = workspace.prepare('files')
		const generation = workspace.begin('call', binding)
		workspace.failed('call', generation, file.name, new TypeError('network timeout'))
		expect(workspace.state.latest?.outcome).toBe('unknown_completion')
		expect(workspace.state.locked).toBe(false)
	})
	it('Stop releases the UI even when a read-only refresh dependency ignores cancellation', async () => {
		const { workspace, refresh } = coordinator()
		refresh.mockImplementationOnce(() => new Promise(() => {}))
		const binding = workspace.prepare('files')
		const generation = workspace.begin('call', binding)
		const stop = new AbortController()
		const completed = workspace.committed('call', generation, binding, { operation: 'create', file }, stop.signal)
		stop.abort(new Error('Stopped'))
		expect(await completed).toMatchObject({ outcome: 'committed', view: 'refresh_required' })
		expect(workspace.state.locked).toBe(false)
	})
	it('a late failure cannot release a newer action lock', () => {
		const { workspace } = coordinator()
		const binding = workspace.prepare('files')
		const previous = workspace.begin('old', binding)
		workspace.failed('old', previous, file.name, new Error())
		workspace.begin('new', binding)
		workspace.failed('old', previous, file.name, new Error())
		expect(workspace.state.locked).toBe(true)
	})
})
