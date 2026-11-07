import { describe, expect, it, vi } from 'vitest'
import { createApp, defineComponent, h, nextTick, ref } from 'vue'
import ActionHistory from '../../src/components/ActionHistory.vue'
import type { NextcloudAgent } from '../../src/agent/createAgent.ts'

vi.mock('@nextcloud/vue/components/NcDialog', () => ({ default: defineComponent({
	props: { open: Boolean, name: String }, emits: ['update:open'],
	template: '<section v-if="open" role="dialog" :aria-label="name"><slot /><slot name="actions" /></section>',
}) }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: defineComponent({
	props: { disabled: Boolean }, template: '<button :disabled="disabled"><slot /></button>',
}) }))

describe('native action-history dialog handoff', () => {
	it.each(['recorded-attempt', 'not_in_active_collection'])('does not claim current resource presence for %s', observed => {
		const host = { history: { items: [], loading: false, error: '', inspection: { action: { id: 'a'.repeat(32) }, observed, outcome: 'unknown', message: 'Audit only.' } },
			historyCommandsAvailable: false, chat: { isBusy: false, version: 0, subscribe: () => () => {} }, refreshHistory: vi.fn() } as unknown as NextcloudAgent
		const root = document.createElement('div')
		document.body.append(root)
		const app = createApp(ActionHistory, { host }); app.mount(root)
		try {
			const text = root.querySelector('.fadenstack-action-inspection')?.textContent
			expect(text).toContain(observed === 'recorded-attempt' ? 'Current native state has not been rechecked' : 'could not be verified in the active collection')
			expect(text).not.toContain('The resource is present.')
			expect(text).toContain('Completion remains uncertain')
		} finally { app.unmount(); root.remove() }
	})
	it('announces a safety pause, disables restoration and leaves inspection available', () => {
		const host = { history: { items: [
			{ id: 'a'.repeat(32), name: 'restore.md', state: 'applied', eligible: 'undo', createdAt: 1 },
			{ id: 'b'.repeat(32), name: 'inspect.md', state: 'needs_reconciliation', eligible: 'reconcile', createdAt: 1 },
		], loading: false, error: '', availability: { writesPaused: true } }, historyCommandsAvailable: true,
		chat: { isBusy: false, version: 0, subscribe: () => () => {} }, refreshHistory: vi.fn() } as unknown as NextcloudAgent
		const root = document.createElement('div')
		document.body.append(root)
		const app = createApp(ActionHistory, { host })
		app.mount(root)
		try {
			expect(root.querySelector('[role="alert"]')?.textContent).toContain('Agent file changes are paused')
			const buttons = Array.from(root.querySelectorAll('button'))
			expect(buttons.find(button => button.textContent?.trim() === 'Undo')?.disabled).toBe(true)
			expect(buttons.find(button => button.textContent?.trim() === 'Inspect outcome')?.disabled).toBe(false)
		} finally { app.unmount(); root.remove() }
	})
	it('explains unverified history without attributing a hashed scope to a particular server', () => {
		const host = { history: { items: [], loading: false, error: '', availability: { unavailableCount: 2, earlierScopeCount: 3, earlierContentExpiresBy: 1900000000 } }, historyCommandsAvailable: true,
			chat: { isBusy: false, version: 0, subscribe: () => () => {} }, refreshHistory: vi.fn() } as unknown as NextcloudAgent
		const root = document.createElement('div')
		document.body.append(root)
		const app = createApp(ActionHistory, { host })
		app.mount(root)
		try {
			expect(root.textContent).toContain('2 earlier actions could not be verified and cannot be restored')
			expect(root.textContent).toContain('3 earlier actions use another connection or previous app settings')
			expect(root.textContent).not.toContain('protected by an earlier server key')
			expect(root.querySelectorAll('button')).toHaveLength(1)
		} finally { app.unmount(); root.remove() }
	})
	it.each([['undo', 'Undo', 'Undo file change'], ['reconcile', 'Inspect outcome', 'Inspect outcome'], ['revalidate', 'Verify file revision', 'Verify file revision']])('unmounts its dialog before the %s command and native editor guard', async (direction, text, label) => {
		const invoked = vi.fn(async () => { expect(document.querySelector('[role="dialog"]')).toBeNull() })
		const host = {
			history: { items: [{ id: 'a'.repeat(32), name: 'report.md', state: 'applied', eligible: direction, version: 2, createdAt: 1 }], loading: false, error: '' },
			historyCommandsAvailable: true, chat: { isBusy: false, version: 0, subscribe: () => () => {} }, refreshHistory: vi.fn(), invokeHistory: invoked,
		} as unknown as NextcloudAgent
		const root = document.createElement('div')
		document.body.append(root)
		const visible = ref(true)
		const app = createApp({ setup: () => () => visible.value ? h(ActionHistory, { host, onClose: () => { visible.value = false } }) : null })
		app.mount(root)
		try {
			const button = Array.from(root.querySelectorAll('button')).find(element => element.textContent?.trim() === text)!
			expect(button).toBeDefined()
			button.click()
			await nextTick()
			await nextTick()
			expect(invoked).toHaveBeenCalledWith(direction, 'a'.repeat(32), label)
		} finally { app.unmount(); root.remove() }
	})
})
