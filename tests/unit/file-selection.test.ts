// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { currentFileSelection, observeFileSelection } from '../../src/workspace/fileSelection.ts'

const row = (id: string, checked = true) => `<div class="files-list__row" data-cy-files-list-row-fileid="${id}"><input type="checkbox" ${checked ? 'checked' : ''}></div>`
const disposers: (() => void)[] = []
afterEach(() => { for (const dispose of disposers.splice(0)) dispose(); document.body.innerHTML = '' })
function child() {
	const frame = document.createElement('iframe'); frame.id = 'fadenstack-native-frame'; document.body.append(frame)
	return frame.contentDocument!
}
function change(page: Document) { page.querySelector('input')!.dispatchEvent(new Event('change', { bubbles: true })) }

describe('Native Files context across child reloads', () => {
	it('reads the active child selection instead of hidden original rows', () => {
		document.body.innerHTML = row('3'); const page = child(); page.body.innerHTML = row('7')
		expect(currentFileSelection()).toBe(7)
	})
	it.each(['0', '-3', '1e3', '1234567890123456'])('rejects noncanonical native IDs: %s', id => {
		document.body.innerHTML = row(id); expect(currentFileSelection()).toBeUndefined()
	})
	it('keeps included context while the agent reloads an empty selection', async () => {
		document.body.innerHTML = row('3'); const selected = vi.fn()
		disposers.push(observeFileSelection(document, selected, () => false)); child()
		await new Promise(resolve => setTimeout(resolve, 0)); expect(selected).not.toHaveBeenCalled()
	})
	it('follows child selection/deselection and ignores hidden parent changes', async () => {
		document.body.innerHTML = row('3'); const selected = vi.fn()
		disposers.push(observeFileSelection(document, selected, () => false)); const page = child()
		page.body.innerHTML = row('7'); await vi.waitFor(() => expect(selected).toHaveBeenLastCalledWith(7))
		change(document); expect(selected).toHaveBeenCalledTimes(1)
		page.querySelector('input')!.checked = false; change(page)
		expect(selected).toHaveBeenLastCalledWith(undefined)
	})
	it('does not replace a frozen turn context while busy and disconnects cleanly', async () => {
		document.body.innerHTML = row('3'); const selected = vi.fn(); let busy = true
		const dispose = observeFileSelection(document, selected, () => busy); disposers.push(dispose)
		const page = child(); page.body.innerHTML = row('7')
		await new Promise(resolve => setTimeout(resolve, 0)); expect(selected).not.toHaveBeenCalled()
		busy = false; await vi.waitFor(() => expect(selected).toHaveBeenLastCalledWith(7))
		dispose(); page.querySelector('input')!.checked = false; change(page); expect(selected).toHaveBeenCalledTimes(1)
	})
	it('retains identity through sorting and clears ambiguous explicit multi-selection', async () => {
		document.body.innerHTML = row('3'); const page = child(); page.body.innerHTML = row('7') + row('8', false)
		const selected = vi.fn(); disposers.push(observeFileSelection(document, selected, () => false))
		page.body.append(page.body.firstElementChild!); await new Promise(resolve => setTimeout(resolve, 0))
		expect(currentFileSelection()).toBe(7); expect(selected).not.toHaveBeenCalled()
		page.querySelector('input')!.checked = true; change(page)
		expect(selected).toHaveBeenLastCalledWith(undefined)
	})
})
