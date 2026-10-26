import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, defineComponent, nextTick, shallowReactive, type App } from 'vue'
import AgentPanel from '../../src/components/AgentPanel.vue'
import AssistantDetails from '../../src/components/AssistantDetails.vue'
import type { Runtime } from '../../src/agent/runtime.ts'
import type { FileContext } from '../../src/integrations/nextcloud/types.ts'

// Native layout/styling is covered in the browser. Keep these tests about the
// host's connection lifecycle, using normal form/button semantics.
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: defineComponent({
	props: { disabled: Boolean, href: String, type: { type: String, default: 'button' } },
	template: '<component :is="href ? \'a\' : \'button\'" :href="href" :type="href ? undefined : type" :disabled="disabled"><slot /></component>',
}) }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { template: '<span role="status">Connecting</span>' } }))
// NcDialog's published entry imports CSS that this Vitest configuration
// externalizes. Exercise its public v-model/slots contract here; real native
// focus trapping, restoration and styling remain browser acceptance checks.
vi.mock('@nextcloud/vue/components/NcDialog', () => ({ default: defineComponent({
	props: { open: Boolean, name: String }, emits: ['update:open'],
	template: '<section v-if="open" role="dialog" :aria-label="name"><button aria-label="Close native dialog" @click="$emit(\'update:open\', false)">Close</button><slot /><slot name="actions" /></section>',
}) }))

const mounted: App[] = []

function fixture(signIn = vi.fn(async () => {})) {
	const state = shallowReactive({ host: undefined, signedIn: false, checking: false, error: '' })
	const runtime = {
		config: { userId: 'alice', binding: 'native-session', enabled: true, serverUrl: 'https://assistant.example.test', agent: 'private-published-profile-slug', development: false },
		state,
		ensure: vi.fn(() => ({ signIn })),
		verify: vi.fn(async () => {}),
		connected: vi.fn(() => { state.signedIn = true }),
		clear: vi.fn(() => { state.signedIn = false }),
	} as unknown as Runtime
	return { runtime, signIn }
}

function mountPanel(runtime: Runtime) {
	const root = document.createElement('div')
	document.body.append(root)
	const app = createApp(AgentPanel, { runtime })
	app.mount(root)
	mounted.push(app)
	return root
}

async function credentials(root: HTMLElement) {
	const email = root.querySelector<HTMLInputElement>('input[autocomplete="username"]')!
	const password = root.querySelector<HTMLInputElement>('input[autocomplete="current-password"]')!
	email.value = ' alice@example.test '
	password.value = 'temporary-test-secret'
	for (const field of [email, password]) field.dispatchEvent(new Event('input', { bubbles: true }))
	await nextTick()
	return { email, password, form: root.querySelector('form')! }
}

afterEach(() => {
	for (const app of mounted.splice(0)) app.unmount()
	document.body.replaceChildren()
	vi.unstubAllGlobals()
})

describe('Nextcloud assistant connection panel', () => {
	it('shows the real sign-in destination without a raw published-profile heading', async () => {
		const { runtime } = fixture()
		const root = mountPanel(runtime)
		await nextTick()
		expect(root.textContent).toContain('Sign in to Fadenstack')
		expect(root.textContent).toContain('assistant.example.test')
		expect(root.textContent).not.toContain('private-published-profile-slug')
		expect(runtime.verify).toHaveBeenCalledOnce()
		expect(runtime.ensure).not.toHaveBeenCalled()
	})

	it('clears the password immediately and sends only one pending sign-in attempt', async () => {
		let finish!: () => void
		const signIn = vi.fn(() => new Promise<void>(resolve => { finish = resolve }))
		const { runtime } = fixture(signIn)
		const root = mountPanel(runtime)
		const { password, form } = await credentials(root)
		form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
		await nextTick()
		expect(signIn).toHaveBeenCalledExactlyOnceWith('alice@example.test', 'temporary-test-secret')
		expect(password.value).toBe('')
		expect(password.disabled).toBe(true)
		form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
		expect(signIn).toHaveBeenCalledTimes(1)
		finish()
		await vi.waitFor(() => expect(runtime.connected).toHaveBeenCalledOnce())
		expect(runtime.clear).not.toHaveBeenCalled()
	})

	it('keeps failed sign-in actionable without retaining the password or claiming connection', async () => {
		const { runtime, signIn } = fixture(vi.fn(async () => { throw new Error('Agent access was denied') }))
		const root = mountPanel(runtime)
		const { password, form } = await credentials(root)
		form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
		await vi.waitFor(() => expect(root.querySelector('[role="alert"]')?.textContent).toContain('Agent access was denied'))
		expect(signIn).toHaveBeenCalledOnce()
		expect(password.value).toBe('')
		expect(password.disabled).toBe(false)
		expect(runtime.clear).toHaveBeenCalledOnce()
		expect(runtime.connected).not.toHaveBeenCalled()
		expect(root.textContent).toContain('assistant.example.test')
	})
})

const selection: FileContext = {
	id: 'opaque-native-context', parentId: 1, expires: 9999999999,
	file: { id: 2, name: '<img src=x onerror="alert(1)">.md', path: '/Project/<script>not executable</script>.md', etag: 'revision-1', mime: 'text/markdown', size: 20, folder: false, permissions: 31, url: new URL('/index.php/f/2', location.origin).href },
}

async function mountDetails(context: FileContext | null = selection, serverUrl = 'https://assistant.example.test') {
	const close = vi.fn()
	const root = document.createElement('div')
	document.body.append(root)
	const app = createApp(AssistantDetails, { title: '<script>Agent title</script>', serverUrl, agent: 'published-agent', context: context ?? undefined, documentContext: true, onClose: close })
	app.mount(root)
	mounted.push(app)
	await vi.waitFor(() => expect(document.querySelector('[role="dialog"]')).not.toBeNull())
	return { dialog: document.querySelector<HTMLElement>('[role="dialog"]')!, close }
}

describe('Assistant details', () => {
	it('shows authorized metadata as text without fetching source content', async () => {
		const fetch = vi.fn()
		vi.stubGlobal('fetch', fetch)
		const { dialog } = await mountDetails()
		expect(dialog.textContent).toContain('<script>Agent title</script>')
		expect(dialog.textContent).toContain(selection.file.name)
		expect(dialog.textContent).toContain(selection.file.path)
		expect(dialog.textContent).toContain('published-agent')
		expect(dialog.textContent).toContain('Only the saved document is available')
		expect(dialog.textContent).toContain('does not grant access')
		expect(dialog.querySelector('script, img[src="x"]')).toBeNull()
		expect(fetch).not.toHaveBeenCalled()
		const nativeLink = dialog.querySelector<HTMLAnchorElement>('a[href]')!
		expect(nativeLink.href).toBe(selection.file.url)
		expect(nativeLink.rel).toBe('noopener noreferrer')
	})

	it.each(['javascript:alert(1)', 'https://other.example.test/private.md', new URL('http://user:password@' + location.host + '/index.php/f/2').href])('refuses an unsafe native selection link: %s', async (url) => {
		const { dialog } = await mountDetails({ ...selection, file: { ...selection.file, url } })
		expect(dialog.textContent).toContain(selection.file.name)
		expect(dialog.querySelector('a[href]')).toBeNull()
	})

	it('does not expose credentials or query details from an invalid connection destination', async () => {
		const { dialog } = await mountDetails(undefined, 'https://user:password@assistant.example.test/path?token=secret')
		expect(dialog.textContent).toContain('Connection unavailable')
		expect(dialog.textContent).not.toContain('password')
		expect(dialog.textContent).not.toContain('token=secret')
	})

	it('describes an absent selection without an invented source or open link', async () => {
		const { dialog } = await mountDetails(null)
		expect(dialog.textContent).toMatch(/No file or folder\b/)
		expect(dialog.textContent).not.toContain(selection.file.name)
		expect(dialog.querySelector('a[href]')).toBeNull()
	})

	it('emits close once from its explicit close action', async () => {
		const { dialog, close } = await mountDetails()
		dialog.querySelector<HTMLButtonElement>('[aria-label="Close assistant details"]')!.click()
		await vi.waitFor(() => expect(close).toHaveBeenCalledOnce())
	})

	it('also emits close when the native dialog requests dismissal', async () => {
		const { dialog, close } = await mountDetails()
		dialog.querySelector<HTMLButtonElement>('[aria-label="Close native dialog"]')!.click()
		await vi.waitFor(() => expect(close).toHaveBeenCalledOnce())
	})
})
