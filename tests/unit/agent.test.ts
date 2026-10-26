import { describe, expect, it, vi } from 'vitest'
import { FakeGateway } from '@fadenstack/testing'
import { ApprovalPart } from '@fadenstack/ui'
import { createApp, markRaw, nextTick } from 'vue'
import AgentChat from '../../src/components/AgentChat.vue'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { APP_UI } from '../../src/agent/appUi.ts'
import contract from '../../contracts/nextcloud-pilot.json'
import { SelectedContext } from '../../src/integrations/nextcloud/context.ts'
import { writeArguments } from '../../src/integrations/nextcloud/tools.ts'
import { installViewerIntegration } from '../../src/integrations/nextcloud/viewer.ts'
import type { FileApi, FileContext, Identity, Proposal } from '../../src/integrations/nextcloud/types.ts'
import type { ScopePolicy } from '../../src/platform/scope.ts'

vi.mock('../../src/components/HeaderButton.vue', () => ({ default: { template: '<button>Fadenstack Agent</button>' } }))

const identity: Identity = { userId: 'alice', binding: 'binding', enabled: true, serverUrl: 'https://faden.test', agent: 'nc', development: false }
const selected: FileContext = { id: 'opaque-context', parentId: 1, expires: 9999999999, file: { id: 2, name: 'source.md', path: '/source.md', etag: 'revision-1', mime: 'text/markdown', size: 11, folder: false, permissions: 31, url: 'https://cloud.test/f/2' } }
function fixture(options: ConstructorParameters<typeof FakeGateway>[0] = {}, hostIdentity: Identity = identity) {
	const gateway = new FakeGateway({ agents: [{ slug: 'nc', version: 1, contract: [
		{ name: 'nc_get_selected_file', risk: 'read' }, { name: 'nc_read_file', risk: 'read' }, { name: 'nc_list_folder', risk: 'read' },
		{ name: 'nc_create_text_file', risk: 'write' }, { name: 'nc_update_text_file', risk: 'write' },
	] }], users: [{ email: 'alice', password: 'test', agents: ['nc'] }], ...options })
	const files: FileApi = {
		identity: vi.fn(async () => hostIdentity), assertIdentity: vi.fn(async () => {}), select: vi.fn(async () => selected),
		read: vi.fn(async () => ({ file: selected.file, text: 'Source text', partial: false, totalCharacters: 11, processedCharacters: 11 })),
		pdf: vi.fn(async () => new Uint8Array()), list: vi.fn(async () => ({ folder: { ...selected.file, folder: true }, files: [], partial: false })),
		propose: vi.fn(async (contextId, args) => ({ id: 'proposal', contextId, ...args, target: '/summary.md', before: null }) satisfies Proposal),
		approve: vi.fn(async () => {}), commit: vi.fn(async () => ({ operation: 'create', file: { ...selected.file, id: 3, name: 'summary.md' } })),
	}
	return { gateway, files, host: new NextcloudAgent(hostIdentity, files, gateway.fetch) }
}
async function pending(host: NextcloudAgent): Promise<ApprovalPart> {
	let part: ApprovalPart | undefined
	await vi.waitFor(() => {
		part = host.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
		expect(part?.isPending).toBe(true)
	})
	return part!
}

describe('SDK integration and file boundary', () => {
	it('Stop aborts native egress scope checks before a model request is sent', async () => {
		const policy: ScopePolicy = { version: 'native-1', sources: [{ kind: 'file', resourceId: 'nextcloud:files:2' }], destinations: [],
			tools: ['nc_read_file'], sourceClasses: ['standard'], gatewayOrigin: identity.serverUrl, downstreamPolicy: 'unverified',
			destinationAudiences: ['private'], movements: [], developmentLoopback: true }
		const { host, files, gateway } = fixture({ model: () => ({ text: 'Never dispatched' }) }, { ...identity, scopePolicy: policy })
		const grant = { resourceId: 'nextcloud:files:2', revision: 'revision-1', sourceClass: 'standard' as const, audience: 'private' as const, scope: policy.sources[0] }
		let revalidating = false
		let reads = 0
		files.scopeResource = vi.fn(async (_reference, purpose, signal) => {
			if (purpose === 'read' && ++reads === 2) {
				revalidating = true
				return new Promise((_resolve, reject) => { signal.addEventListener('abort', () => reject(signal.reason), { once: true }) })
			}
			return grant
		})
		await host.signIn('alice', 'test')
		host.offerSelection(await host.selection.select(2))
		const sending = host.send('Read the source')
		await vi.waitFor(() => expect(revalidating).toBe(true))
		host.stop()
		await sending
		expect(gateway.modelCalls).toHaveLength(0)
		expect(host.chat.isBusy).toBe(false)
		await host.dispose()
	})
	it('narrows the real SDK tool manifest and reauthorizes prior conversation sources before replay', async () => {
		const policy: ScopePolicy = { version: 'native-1', sources: [{ kind: 'file', resourceId: 'nextcloud:files:2' }], destinations: [],
			tools: ['nc_read_file'], sourceClasses: ['standard'], gatewayOrigin: identity.serverUrl, downstreamPolicy: 'unverified',
			destinationAudiences: ['private'], movements: [], developmentLoopback: true }
		const { host, files, gateway } = fixture({ model: () => ({ text: 'Read source' }) }, { ...identity, scopePolicy: policy })
		files.scopeResource = vi.fn(async reference => ({ resourceId: reference, revision: 'revision-1', sourceClass: 'standard', audience: 'private', scope: policy.sources[0] }))
		await host.signIn('alice', 'test')
		host.offerSelection(await host.selection.select(2))
		await host.send('Read this file')
		expect(gateway.modelCalls).toHaveLength(1)
		expect(gateway.modelCalls[0]!.tools?.map(tool => tool.name)).toEqual(['nc_read_file'])
		vi.mocked(files.scopeResource).mockRejectedValue(new Error('Native access revoked'))
		await expect(host.send('Continue with the same source', false)).rejects.toThrow('Native access revoked')
		expect(gateway.modelCalls).toHaveLength(1)
		// Revocation must not prevent token revocation / sign-out.
		await expect(host.dispose()).resolves.toBeUndefined()
	})
	it('blocks a forged model reference at the actual host boundary', async () => {
		let round = 0
		const policy: ScopePolicy = { version: 'native-1', sources: [{ kind: 'file', resourceId: 'nextcloud:files:2' }], destinations: [],
			tools: ['nc_read_file'], sourceClasses: ['standard'], gatewayOrigin: identity.serverUrl, downstreamPolicy: 'unverified',
			destinationAudiences: ['private'], movements: [], developmentLoopback: true }
		const { host, files } = fixture({ model: () => ++round === 1 ? { toolCalls: [{ id: 'forged', name: 'nc_read_file', arguments: { resource_id: 'nextcloud:files:3' } }] } : { text: 'Unavailable' } }, { ...identity, scopePolicy: policy })
		files.scopeResource = vi.fn(async reference => ({ resourceId: reference, revision: 'revision-1', sourceClass: 'standard', audience: 'private', scope: policy.sources[0] }))
		await host.signIn('alice', 'test')
		await host.send('Read a different file', false)
		expect(files.read).not.toHaveBeenCalled()
		expect(files.scopeResource).not.toHaveBeenCalled()
		await host.dispose()
	})
	it('refuses a final proposal body different from the arguments reviewed by the SDK', async () => {
		let round = 0
		const { host, files } = fixture({ model: () => ++round === 1 ? { toolCalls: [{ id: 'changed', name: 'nc_create_text_file', arguments: { filename: 'summary.md', content: 'Reviewed body' } }] } : { text: 'Finished' } })
		vi.mocked(files.propose).mockImplementation(async (contextId, args) => ({ id: 'proposal', contextId, ...args, target: '/summary.md', before: null, content: 'Changed by module' }))
		await host.signIn('alice', 'test')
		host.offerSelection(await host.selection.select(2))
		const sending = host.send('Create a note')
		await host.approve(await pending(host))
		await sending
		expect(files.commit).not.toHaveBeenCalled()
		await host.dispose()
	})
	it('uses the Nextcloud language for SDK labels independently of browser language', async () => {
		const { host, files, gateway } = fixture()
		const german = new NextcloudAgent({ ...identity, locale: 'de-DE' }, files, gateway.fetch)
		expect([german.chat.strings.language, german.chat.strings.allow, german.chat.strings.approvalClass.destructive]).toEqual(['de', 'Zulassen', 'Einzelne Freigabe'])
		await german.dispose()
		await host.dispose()
	})
	it('allows an explicitly included folder reference as a destination without reading document bytes', async () => {
		let round = 0
		const { host, files } = fixture({ model: () => ++round === 1 ? { toolCalls: [{ name: 'nc_create_text_file', arguments: { filename: 'summary.md', content: '# Summary', resource_id: 'nextcloud:files:9' }, id: 'folder-write' }] } : { text: 'Created in folder' } })
		const folder = { ...selected, parentId: 9, file: { ...selected.file, id: 9, folder: true, name: 'Engineering', path: '/Engineering', mime: 'httpd/unix-directory' } }
		vi.mocked(files.select).mockResolvedValue(folder)
		await host.signIn('alice', 'test')
		host.offerSelection(await host.selection.select(9))
		expect(host.discovery.issued.size).toBe(0)
		const sending = host.send('Create a summary in this included folder')
		const part = await pending(host)
		expect(files.read).not.toHaveBeenCalled()
		expect(files.pdf).not.toHaveBeenCalled()
		expect(files.commit).not.toHaveBeenCalled()
		await host.approve(part)
		await sending
		expect(files.commit).toHaveBeenCalledTimes(1)
		await host.dispose()
	})
	it('renders native search cards, adds context lazily, finds a passage and gates a searched-file write', async () => {
		vi.stubGlobal('ResizeObserver', class { observe() {} disconnect() {} })
		let round = 0
		const { host, files } = fixture({ agents: [{ slug: 'nc', version: 1, contract: [
			...['nc_search_files', 'nc_get_file_metadata', 'nc_search_in_file', 'nc_search_content', 'nc_get_selected_file', 'nc_read_file', 'nc_list_folder'].map(name => ({ name, risk: 'read' as const })),
			{ name: 'nc_create_text_file', risk: 'write' }, { name: 'nc_update_text_file', risk: 'write' },
		] }], model: () => {
			round++
			if (round === 1) return { toolCalls: [{ name: 'nc_search_files', arguments: { query: 'source' }, id: 'search-call' }] }
			if (round === 3) return { toolCalls: [{ name: 'nc_search_in_file', arguments: { query: 'termination' }, id: 'passage-call' }] }
			if (round === 5) return { toolCalls: [{ name: 'nc_create_text_file', arguments: { filename: 'summary.md', content: '# Summary\n30 days notice.', resource_id: 'nextcloud:files:2' }, id: 'searched-write' }] }
			return { text: round === 2 ? 'Found the file.' : round === 4 ? '30 days written notice.' : 'Created summary.' }
		} })
		const source = { ...selected, file: { ...selected.file, url: new URL('/f/2', location.origin).href, modified: 1791797400 } }
		vi.mocked(files.select).mockResolvedValue(source)
		vi.mocked(files.read).mockResolvedValue({ file: source.file, text: 'Termination notice: 30 days written notice.', partial: false, totalCharacters: 42, processedCharacters: 42 })
		files.searchTransport = {
			plan: vi.fn(async () => ({ filters: { query: 'source', scope: 'all', limit: 20, sort_by: 'relevance', sort_direction: 'desc' }, queries: ['native query'], backend: 'webdav-search' })),
			dav: vi.fn(async () => '<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:response><d:propstat><d:prop><oc:fileid>2</oc:fileid></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>'),
			resolve: vi.fn(async () => [source.file]), capabilities: vi.fn(), content: vi.fn(),
		}
		await host.signIn('alice', 'test')
		const root = document.createElement('div')
		document.body.append(root)
		const app = createApp(AgentChat, { host: markRaw(host) })
		app.mount(root)
		try {
			const send = async (text: string) => {
				const input = root.querySelector('textarea')!
				input.value = text; input.dispatchEvent(new Event('input', { bubbles: true })); await nextTick()
				root.querySelector<HTMLButtonElement>('.fdn-send')!.click()
			}
			await send('Find the source file')
			await vi.waitFor(() => expect(host.chat.isBusy).toBe(false))
			await vi.waitFor(() => expect(root.querySelector('.fdn-search-file')?.textContent).toContain('source.md'))
			expect(root.querySelector('.fdn-search-name a')?.getAttribute('href')).toBe(source.file.url)
			expect(files.read).not.toHaveBeenCalled()
			const attach = root.querySelector<HTMLButtonElement>('.fdn-search-actions button')!
			expect(attach.disabled).toBe(false)
			attach.click()
			await vi.waitFor(() => expect(host.chat.attachments[0]?.title).toBe('source.md'))
			expect(files.read).not.toHaveBeenCalled()
			await send('Find the termination passage')
			await vi.waitFor(() => expect(root.querySelector('.fdn-search-passage')?.textContent).toContain('30 days written notice'))
			await vi.waitFor(() => expect(host.chat.isBusy).toBe(false))
			expect(files.read).toHaveBeenCalledTimes(1)
			await send('Create a Markdown summary')
			await pending(host)
			expect(files.commit).not.toHaveBeenCalled()
			await nextTick()
			root.querySelector<HTMLButtonElement>('.fdn-approval .fdn-primary')!.click()
			await vi.waitFor(() => expect(host.chat.isBusy).toBe(false))
			expect(files.commit).toHaveBeenCalledTimes(1)
			expect(root.textContent).toContain('Created summary.')
		} finally { app.unmount(); root.remove(); await host.dispose(); vi.unstubAllGlobals() }
	})
	it('sends from the Vue composer and gates real SDK writes through its approval buttons', async () => {
		vi.stubGlobal('ResizeObserver', class { observe() {} disconnect() {} })
		let round = 0
		const { host, files, gateway } = fixture({ model: () => ++round === 1 ? { toolCalls: [{ name: 'nc_create_text_file', arguments: { filename: 'summary.md', content: '# Summary' }, id: 'composer-write' }] } : { text: 'Approved result' } })
		await host.signIn('alice', 'test')
		host.offerSelection(await host.selection.select(2))
		const root = document.createElement('div')
		document.body.append(root)
		const app = createApp(AgentChat, { host: markRaw(host), context: selected })
		app.mount(root)
		const disconnectViewer = installViewerIntegration({ drawer: root, state: { open: true }, toggle: () => {}, changed: () => {} })
		const viewer = document.createElement('div')
		viewer.id = 'viewer'
		viewer.className = 'modal-mask viewer'
		viewer.setAttribute('role', 'dialog')
		viewer.innerHTML = '<div class="modal-header"><h2 class="modal-header__name">source.md</h2><div class="icons-menu"></div></div>'
		document.body.append(viewer)
		await vi.waitFor(() => expect(root.parentElement).toBe(viewer))
		try {
			const input = root.querySelector('textarea')!
			input.value = 'Create a summary'
			input.dispatchEvent(new Event('input', { bubbles: true }))
			await nextTick()
			const send = root.querySelector<HTMLButtonElement>('.fdn-send')!
			expect(send.disabled).toBe(false)
			send.click()
			await pending(host)
			await nextTick()
			expect(files.commit).not.toHaveBeenCalled()
			root.querySelector<HTMLButtonElement>('.fdn-approval .fdn-primary')!.click()
			await vi.waitFor(() => expect(host.chat.isBusy).toBe(false))
			expect(files.commit).toHaveBeenCalledTimes(1)
			expect(root.textContent).toContain('Approved result')
			input.value = 'Follow-up question'
			input.dispatchEvent(new Event('input', { bubbles: true }))
			await nextTick()
			input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', shiftKey: true, bubbles: true }))
			expect(gateway.modelCalls).toHaveLength(2)
			input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
			await vi.waitFor(() => expect(gateway.modelCalls).toHaveLength(3))
			await vi.waitFor(() => expect(host.chat.isBusy).toBe(false))
		} finally { disconnectViewer(); app.unmount(); root.remove(); viewer.remove(); await host.dispose(); vi.unstubAllGlobals() }
	})
	it('offers lazy composer context, respects exclusion, and freezes the sent chip', async () => {
		const { host, files, gateway } = fixture({ model: () => ({ text: 'A grounded answer' }) })
		await host.signIn('alice', 'test')
		host.offerSelection(await host.selection.select(2))
		const chip = host.chat.attachments[0]!
		expect(chip.title).toBe('source.md')
		expect(files.read).not.toHaveBeenCalled()
		expect(gateway.modelCalls).toHaveLength(0)
		chip.toggle()
		await host.send('General question')
		expect(files.read).not.toHaveBeenCalled()
		expect(host.chat.turns.at(-1)?.attachments).toHaveLength(0)
		chip.toggle()
		await host.send('Summarize')
		expect(files.read).toHaveBeenCalledTimes(1)
		expect(host.chat.turns.at(-1)?.attachments[0]?.title).toBe('source.md')
		expect(host.chat.attachments[0]).toBe(chip)
		host.offerSelection()
		expect(host.chat.attachments).toHaveLength(0)
		await host.dispose()
	})
	it('reports the five tools through the SDK manifest for automatic discovery', async () => {
		const { host, gateway } = fixture()
		await host.signIn('alice', 'test')
		await vi.waitFor(() => expect(gateway.manifests).toHaveLength(1))
		const manifest = gateway.manifests[0]!.manifest
		expect((manifest.tools as { name: string }[]).map(tool => tool.name)).toEqual(['nc_get_file_metadata', 'nc_search_in_file', 'nc_search_content', 'nc_search_files', 'nc_get_selected_file', 'nc_read_file', 'nc_list_folder', 'nc_create_text_file', 'nc_update_text_file'])
		expect(JSON.stringify(manifest)).not.toContain('Source text')
		// The greeting and suggestions an administrator can take in on the Apps tab, the same as the contract's.
		expect(manifest.ui).toEqual({ greeting: APP_UI.greeting, suggestions: [...APP_UI.suggestions] })
		expect(contract.version.ui).toEqual(manifest.ui)
		await host.dispose()
	})
	it('selects metadata without reading or sending it, and includes only after deliberate send', async () => {
		const { host, files, gateway } = fixture({ model: () => ({ text: 'A grounded answer' }) })
		await host.signIn('alice', 'test')
		await host.selection.select(2)
		expect(files.read).not.toHaveBeenCalled()
		expect(gateway.modelCalls).toHaveLength(0)
		await host.send('General question', false)
		expect(files.read).not.toHaveBeenCalled()
		await host.send('Summarize', true)
		expect(files.read).toHaveBeenCalledWith(selected.id, expect.any(AbortSignal))
		expect(JSON.stringify(gateway.modelCalls.at(-1)?.messages)).toContain('Source text')
		expect(host.chat.turns.at(-1)?.answerText).toBe('A grounded answer')
		await host.dispose()
	})
	it('requires individual approval even in Auto and commits the exact proposal only once', async () => {
		let round = 0
		const { host, files } = fixture({ model: () => ++round === 1 ? { toolCalls: [{ name: 'nc_create_text_file', arguments: { filename: 'summary.md', content: '# Summary' }, id: 'call1' }] } : { text: 'Created' } })
		await host.signIn('alice', 'test')
		await host.selection.select(2)
		host.chat.selectToolMode('auto')
		const sending = host.send('Create a summary', true)
		const part = await pending(host)
		expect(part.request.arguments.target).toBe('/summary.md')
		expect(part.request.arguments.content).toBe('# Summary')
		expect(part.canAllowForSession).toBe(false)
		expect(files.commit).not.toHaveBeenCalled()
		await host.approve(part)
		await sending
		expect(files.approve).toHaveBeenCalledWith('proposal', expect.any(AbortSignal))
		expect(files.commit).toHaveBeenCalledTimes(1)
		await host.dispose()
	})
	it('denial prevents writes', async () => {
		let round = 0
		const { host, files } = fixture({ model: () => ++round === 1 ? { toolCalls: [{ name: 'nc_create_text_file', arguments: { filename: 'summary.md', content: 'Text' }, id: 'call1' }] } : { text: 'Denied' } })
		await host.signIn('alice', 'test')
		await host.selection.select(2)
		const sending = host.send('Create', true)
		;(await pending(host)).deny()
		await sending
		expect(files.approve).not.toHaveBeenCalled()
		expect(files.commit).not.toHaveBeenCalled()
		await host.dispose()
	})
	it('freezes a turn context when the selected file changes', async () => {
		let round = 0
		const { host, files } = fixture({ model: () => ++round === 1 ? { toolCalls: [{ name: 'nc_create_text_file', arguments: { filename: 'summary.md', content: 'Text' }, id: 'call1' }] } : { text: 'Done' } })
		await host.signIn('alice', 'test')
		await host.selection.select(2)
		const sending = host.send('Create', true)
		const part = await pending(host)
		vi.mocked(files.select).mockResolvedValueOnce({ ...selected, id: 'other-context', file: { ...selected.file, id: 9 } })
		await host.selection.select(9)
		expect(host.context().id).toBe(selected.id)
		part.deny()
		await sending
		expect(() => host.context()).toThrow('Include')
		await host.dispose()
	})
	it('respects a contract that omits tools and the administrator tool-mode ceiling', async () => {
		const { host } = fixture({ agents: [{ slug: 'nc', ceiling: 'read_only', contract: [{ name: 'nc_read_file', risk: 'read' }] }] })
		await host.signIn('alice', 'test')
		expect(host.agent.tools.map(t => t.name)).toEqual(['nc_read_file'])
		host.chat.selectToolMode('auto')
		expect(host.chat.toolMode).toBe('read_only')
		await host.dispose()
	})
	it('refuses failed authentication and lost grants', async () => {
		const { host, gateway } = fixture()
		await expect(host.signIn('alice', 'wrong')).rejects.toThrow()
		await host.signIn('alice', 'test')
		gateway.revoke()
		gateway.expireAccessTokens()
		await host.send('Question', false)
		expect(host.chat.signInRequired).toBe(true)
		await host.dispose()
	})
	it('uses fresh memory stores across Nextcloud identities and sign-out', async () => {
		const { host, gateway } = fixture({ model: () => ({ text: 'Alice private answer' }) })
		await host.signIn('alice', 'test')
		await host.send('Alice private message', false)
		expect(await host.agent.listSessions()).toHaveLength(1)
		await host.dispose()
		expect(await host.tokens.isSignedIn()).toBe(false)
		const second = fixture().host
		await second.signIn('alice', 'test')
		expect(second.chat.turns).toHaveLength(0)
		expect(await second.agent.listSessions()).toHaveLength(0)
		expect(gateway.modelCalls).toHaveLength(1)
		await second.dispose()
	})
	it('rejects changed Nextcloud sessions before sending content', async () => {
		const { host, files, gateway } = fixture()
		await host.signIn('alice', 'test')
		await host.selection.select(2)
		vi.mocked(files.assertIdentity).mockRejectedValue(new Error('Session changed'))
		await expect(host.send('Summarize', true)).rejects.toThrow('Session changed')
		expect(files.read).not.toHaveBeenCalled()
		expect(gateway.modelCalls).toHaveLength(0)
		await host.dispose()
	})
})
describe('The SDK panel in Nextcloud', () => {
	it('labels the frozen source for a busy response and then follows the next selection', async () => {
		vi.stubGlobal('ResizeObserver', class { observe() {} disconnect() {} })
		let finish!: (reply: { text: string }) => void
		const response = new Promise<{ text: string }>(resolve => { finish = resolve })
		const { host, files, gateway } = fixture({ model: () => response })
		await host.signIn('alice', 'test')
		host.offerSelection(await host.selection.select(2))
		const root = document.createElement('div')
		document.body.append(root)
		const app = createApp(AgentChat, { host: markRaw(host), context: selected })
		app.mount(root)
		let sending: Promise<void> | undefined
		try {
			expect(root.querySelector('.fadenstack-context')?.textContent).toContain('Next message')
			expect(root.querySelector('.fadenstack-context')?.textContent).toContain('source.md')
			expect(host.turnContext).toBeUndefined()
			sending = host.send('Summarize the included source')
			await vi.waitFor(() => expect(gateway.modelCalls).toHaveLength(1))
			expect(host.chat.isBusy).toBe(true)
			expect(host.turnContext?.file.id).toBe(2)
			const later = { ...selected, id: 'later-context', file: { ...selected.file, id: 9, name: 'later.md', path: '/later.md' } }
			vi.mocked(files.select).mockResolvedValueOnce(later)
			host.offerSelection(await host.selection.select(9))
			await nextTick()
			const busyContext = root.querySelector('.fadenstack-context')?.textContent
			expect(busyContext).toContain('This response')
			expect(busyContext).toContain('source.md')
			expect(busyContext).not.toContain('later.md')
			expect(host.turnContext?.file.id).toBe(2)
			expect(files.read).toHaveBeenCalledTimes(1)
			expect(JSON.stringify(gateway.modelCalls[0]?.messages)).not.toContain('later.md')
			finish({ text: 'Summary complete' })
			await sending
			await nextTick()
			expect(host.turnContext).toBeUndefined()
			expect(root.querySelector('.fadenstack-context')?.textContent).toContain('Next message')
			expect(root.querySelector('.fadenstack-context')?.textContent).toContain('later.md')
		} finally {
			finish({ text: 'Summary complete' })
			await sending?.catch(() => {})
			app.unmount(); root.remove(); await host.dispose(); vi.unstubAllGlobals()
		}
	})

	it('reflects an excluded implicit attachment without reading it or claiming it grounds a response', async () => {
		vi.stubGlobal('ResizeObserver', class { observe() {} disconnect() {} })
		let finish!: (reply: { text: string }) => void
		const response = new Promise<{ text: string }>(resolve => { finish = resolve })
		const { host, files, gateway } = fixture({ model: () => response })
		await host.signIn('alice', 'test')
		host.offerSelection(await host.selection.select(2))
		const root = document.createElement('div')
		document.body.append(root)
		const app = createApp(AgentChat, { host: markRaw(host), context: selected })
		app.mount(root)
		let sending: Promise<void> | undefined
		try {
			expect(host.selectionIncluded).toBe(true)
			root.querySelector<HTMLButtonElement>('.fdn-chip-toggle')!.click()
			await nextTick()
			expect(host.selectionIncluded).toBe(false)
			expect(root.querySelector('.fadenstack-context')?.textContent).toContain('Not included')
			expect(files.read).not.toHaveBeenCalled()
			expect(gateway.modelCalls).toHaveLength(0)
			sending = host.send('General question without the source')
			await vi.waitFor(() => expect(gateway.modelCalls).toHaveLength(1))
			expect(host.turnContext).toBeUndefined()
			expect(root.querySelector('.fadenstack-context')?.textContent).toContain('This response')
			expect(root.querySelector('.fadenstack-context')?.textContent).toContain('No file included in this response.')
			expect(root.querySelector('.fadenstack-context')?.textContent).not.toContain('source.md')
			expect(files.read).not.toHaveBeenCalled()
			finish({ text: 'General answer' })
			await sending
			await nextTick()
			expect(root.querySelector('.fadenstack-context')?.textContent).toContain('Not included')
		} finally {
			finish({ text: 'General answer' })
			await sending?.catch(() => {})
			app.unmount(); root.remove(); await host.dispose(); vi.unstubAllGlobals()
		}
	})

	it('opens and closes real native Details without reading files or asking the model', async () => {
		vi.stubGlobal('ResizeObserver', class { observe() {} disconnect() {} })
		const { host, files, gateway } = fixture()
		await host.signIn('alice', 'test')
		host.offerSelection(await host.selection.select(2))
		const root = document.createElement('div')
		document.body.append(root)
		const app = createApp(AgentChat, { host: markRaw(host), context: selected })
		app.mount(root)
		try {
			const trigger = root.querySelector<HTMLButtonElement>('[aria-label="Assistant details"]')!
			trigger.focus()
			trigger.click()
			await vi.waitFor(() => expect(document.querySelector('[role="dialog"]')?.textContent).toContain('source.md'))
			// Wait for the real native enter transition/focus trap before closing;
			// removing it earlier leaves its deferred activation without a node.
			await vi.waitFor(() => expect(document.querySelector('[role="dialog"]')?.contains(document.activeElement)).toBe(true))
			expect(files.select).toHaveBeenCalledTimes(1)
			expect(files.read).not.toHaveBeenCalled()
			expect(files.pdf).not.toHaveBeenCalled()
			expect(files.list).not.toHaveBeenCalled()
			expect(gateway.modelCalls).toHaveLength(0)
			document.querySelector<HTMLButtonElement>('[aria-label="Close assistant details"]')!.click()
			await vi.waitFor(() => expect(trigger.getAttribute('aria-expanded')).toBe('false'))
			await vi.waitFor(() => expect(document.activeElement).toBe(trigger))
			expect(gateway.modelCalls).toHaveLength(0)
		} finally { app.unmount(); root.remove(); await host.dispose(); vi.unstubAllGlobals() }
	})

	it('keeps Esc in the panel and has the account in its settings', async () => {
		vi.stubGlobal('ResizeObserver', class { observe() {} disconnect() {} })
		const { host } = fixture()
		await host.signIn('alice', 'test')
		const drawer = document.createElement('div')
		const root = document.createElement('div')
		drawer.append(root)
		document.body.append(drawer)
		let reachedDrawer = 0
		drawer.addEventListener('keydown', (event) => { if (event.key === 'Escape') reachedDrawer++ })
		const signedOut = vi.fn()
		const app = createApp(AgentChat, { host: markRaw(host), onSignedOut: signedOut })
		app.mount(root)
		try {
			host.chat.setSettingsOpen(true)
			await nextTick()
			expect(root.textContent).toContain('Sign out')
			root.querySelector('.fdn-chat')!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
			await nextTick()
			expect([host.chat.isSettingsOpen, reachedDrawer]).toEqual([false, 0]) // the menu closed, the drawer did not
			host.chat.setSettingsOpen(true)
			await nextTick()
			const signOut = [...root.querySelectorAll<HTMLButtonElement>('.fdn-menu-item')].find(button => button.textContent?.includes('Sign out'))!
			signOut.click()
			expect([signedOut.mock.calls.length, host.chat.isSettingsOpen]).toEqual([1, false])
		} finally { app.unmount(); drawer.remove(); await host.dispose(); vi.unstubAllGlobals() }
	})
})

describe('Selection and arguments', () => {
	it('discards late metadata lookups', async () => {
		const { files } = fixture()
		let first!: (value: FileContext) => void
		vi.mocked(files.select).mockReturnValueOnce(new Promise(resolve => { first = resolve }))
		const selection = new SelectedContext(files)
		const old = selection.select(1)
		await selection.select(2)
		first({ ...selected, id: 'old' })
		expect(await old).toBeUndefined()
		expect(selection.snapshot()?.id).toBe(selected.id)
	})
	it.each(['../secret.md', '/root.md', '.hidden.md', 'file.php', 'foo\\bar.md', 'name.md '])('rejects unsafe filename %s', filename => {
		expect(() => writeArguments('nc_create_text_file', { filename, content: 'Text' })).toThrow()
	})
	it('requires a revision for updates and bounds content', () => {
		expect(() => writeArguments('nc_update_text_file', { content: 'Text' })).toThrow('ETag')
		expect(() => writeArguments('nc_create_text_file', { filename: 'ok.md', content: 'x'.repeat(262145) })).toThrow('256')
	})
})
