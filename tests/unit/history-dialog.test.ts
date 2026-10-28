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
