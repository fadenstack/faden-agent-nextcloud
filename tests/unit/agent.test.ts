import { describe, expect, it, vi } from 'vitest'
import { FakeGateway } from '../helpers/gateway071.ts'
import { FakeGateway as LegacyGateway } from '@fadenstack/testing'
import { MemoryAuditLog } from '@fadenstack/agents'
import { ApprovalPart } from '@fadenstack/ui'
import { createApp, markRaw, nextTick } from 'vue'
import AgentChat from '../../src/components/AgentChat.vue'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { APP_UI } from '../../src/agent/appUi.ts'
import contract from '../../contracts/nextcloud-pilot.json'
import { SelectedContext } from '../../src/integrations/nextcloud/context.ts'
import { writeArguments } from '../../src/integrations/nextcloud/tools.ts'
import { withFrontMatter, readerTextSha256 } from '@fadenstack/provenance'
import { installViewerIntegration } from '../../src/integrations/nextcloud/viewer.ts'
import { isAgentDisabled } from '../../src/integrations/nextcloud/files.ts'
import type { FileApi, FileContext, Identity, Proposal } from '../../src/integrations/nextcloud/types.ts'
import type { ScopePolicy } from '../../src/platform/scope.ts'

vi.mock('../../src/components/HeaderButton.vue', () => ({ default: { template: '<button>Fadenstack Agent</button>' } }))

const identity: Identity = { userId: 'alice', binding: 'binding', enabled: true, serverUrl: 'https://faden.test', agent: 'nc', development: false }
const selected: FileContext = { id: 'opaque-context', parentId: 1, expires: 9999999999, file: { id: 2, name: 'source.md', path: '/source.md', etag: 'revision-1', mime: 'text/markdown', size: 11, folder: false, permissions: 31, url: 'https://cloud.test/f/2' } }
function fixture(options: ConstructorParameters<typeof FakeGateway>[0] = {}, hostIdentity: Identity = identity, fileChanges: Partial<FileApi> = {}) {
	const gateway = new FakeGateway({
		agents: [{
			slug: 'nc',
			version: 1,
			contract: [
				{ name: 'nc_get_selected_file', risk: 'read' }, { name: 'nc_read_file', risk: 'read' }, { name: 'nc_list_folder', risk: 'read' },
				{ name: 'nc_create_text_file', risk: 'write' }, { name: 'nc_update_text_file', risk: 'write' },
			],
		}],
		users: [{ email: 'alice', password: 'test', agents: ['nc'] }],
		...options,
	})
	const files: FileApi = {
		identity: vi.fn(async () => hostIdentity),
		assertIdentity: vi.fn(async () => {}),
		select: vi.fn(async () => selected),
		read: vi.fn(async () => ({ file: selected.file, text: 'Source text', partial: false, totalCharacters: 11, processedCharacters: 11 })),
		pdf: vi.fn(async () => new Uint8Array()),
		list: vi.fn(async () => ({ folder: { ...selected.file, folder: true }, files: [], partial: false })),
		propose: vi.fn(async (contextId, args) => ({ id: 'proposal', contextId, ...args, target: '/summary.md', before: null }) satisfies Proposal),
		approve: vi.fn(async () => {}),
		commit: vi.fn(async () => ({ operation: 'create', file: { ...selected.file, id: 3, name: 'summary.md' } })),
		...fileChanges,
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
	it('requires the fresh core dispatch capability rather than silently using an older gateway', async () => {
		const legacy = new LegacyGateway({ agents: [{ slug: 'nc', version: 1 }], users: [{ email: 'alice', password: 'test', agents: ['nc'] }] })
		const { host: unused, files } = fixture()
		await unused.dispose()
		const host = new NextcloudAgent(identity, files, legacy.fetch)
		try { await expect(host.signIn('alice', 'test')).rejects.toThrow(/fresh central host-tool dispatch checks/) }
		finally { await host.dispose() }
	})
	it('refuses native dispatch when central policy denies the already approved model call', async () => {
		let round = 0
		const { host, files, gateway } = fixture({ model: () => ++round === 1
			? { toolCalls: [{ id: 'blocked-create', name: 'nc_create_text_file', arguments: { filename: 'Draft.md', content: 'Synthetic draft' } }] }
			: { text: 'No file was saved.' } })
		gateway.denyDispatch = true
		try {
			await host.signIn('alice', 'test'); host.offerSelection(await host.selection.select(2))
			const work = host.send('Create a draft'); const part = await pending(host)
			await host.approve(part); await work
			expect(gateway.dispatchChecks.filter(c => c.name === 'nc_create_text_file').map(c => c.phase)).toEqual(['prepare', 'dispatch'])
			expect(files.commit).not.toHaveBeenCalled()
		} finally { host.stop(); await host.dispose() }
	})
	it('pairing signs in the existing token provider without submitting a password', async () => {
		const { host, gateway, files } = fixture({ pairing: true }, { ...identity, fileProvenanceEnabled: true }, { setCloudIdentity: vi.fn() })
		try {
			const start = await host.startPairing(); gateway.approvePairing('alice', start.userCode)
			await host.completePairing(start)
			expect(host.chat.isReady).toBe(true)
			expect(host.tokens.deviceId).toBeTruthy()
			expect(files.setCloudIdentity).toHaveBeenCalled()
			expect(gateway.requests.some(r => r.path.endsWith('/api/auth/jwt/login'))).toBe(false)
		} finally { await host.dispose() }
	}, 15000)
	it('cancelled pairing cannot initialize a native agent or dispatch a tool', async () => {
		const { host, gateway, files } = fixture({ pairing: true })
		try {
			const start = await host.startPairing(); const stop = new AbortController(); stop.abort()
			await expect(host.completePairing(start, stop.signal)).rejects.toThrow()
			expect(host.chat.isReady).toBe(false)
			expect(gateway.modelCalls).toHaveLength(0)
			expect(files.commit).not.toHaveBeenCalled()
		} finally { await host.dispose() }
	})
	it('an agent the organisation turned off says so in the chat, asks no model and writes nothing, until Check again finds it on', async () => {
		const { host, gateway, files } = fixture({ model: () => ({ toolCalls: [{ id: 'create', name: 'nc_create_text_file', arguments: { filename: 'Draft.md', content: 'Synthetic' } }] }) })
		try {
			await host.signIn('alice', 'test'); host.offerSelection(await host.selection.select(2))
			gateway.turnOff()
			await host.send('Create a draft')
			expect(host.chat.isAgentOff).toBe(true)
			expect(host.chat.agentOffText).toBe('Your organisation turned this agent off.')
			expect(host.chat.isInputEnabled).toBe(false)
			const refused = gateway.refusedWhileOff(gateway.offAt).length
			await host.send('Try once more')
			expect(gateway.refusedWhileOff(gateway.offAt)).toHaveLength(refused)
			expect(gateway.modelCalls).toHaveLength(0)
			expect(files.propose).not.toHaveBeenCalled()
			expect(files.commit).not.toHaveBeenCalled()
			gateway.turnOn()
			await host.chat.checkAgain()
			expect(host.chat.isAgentOff).toBe(false)
		} finally { host.stop(); await host.dispose() }
	})
	it('hands the Nextcloud off-switch refusal to the SDK, which shows its notice once and asks nothing more (rc21 live run)', async () => {
		const off = () => Object.assign(new Error('Your organisation turned this agent off.'), { agentDisabled: true })
		const { host, gateway, files } = fixture()
		try {
			await host.signIn('alice', 'test'); host.offerSelection(await host.selection.select(2))
			// Nextcloud says off, but the profile still says on: the refusal is shown as it is.
			// One preparation step refuses (in the live run: files/operations/capabilities); Nextcloud's other checks pass.
			vi.mocked(files.assertIdentity).mockRejectedValueOnce(off())
			expect(isAgentDisabled(await host.send('Before the switch reaches the server').catch(error => error))).toBe(true)
			expect(host.chat.error).toBe('Your organisation turned this agent off.')
			expect(host.chat.isAgentOff).toBe(false)
			// Nextcloud says off and the profile agrees: the SDK's notice with Check again, no second copy, no model call.
			gateway.turnOff()
			vi.mocked(files.assertIdentity).mockRejectedValueOnce(off())
			expect(isAgentDisabled(await host.send('After the switch').catch(error => error))).toBe(true)
			expect(host.chat.isAgentOff).toBe(true)
			expect(host.chat.error).toBeFalsy()
			expect(gateway.modelCalls).toHaveLength(0)
			expect(gateway.refusedWhileOff(gateway.offAt)).toHaveLength(0)
		} finally { await host.dispose() }
	})
	it('signs in to an agent that is turned off and shows the notice instead of failing the sign-in', async () => {
		const { host, gateway } = fixture()
		gateway.turnOff()
		try {
			await host.signIn('alice', 'test')
			expect(host.chat.isReady).toBe(true)
			expect(host.chat.isAgentOff).toBe(true)
		} finally { await host.dispose() }
	})
	it('Stop everything cancels the waiting native proposal, stops the turn and says what it did', async () => {
		const { host, files } = fixture({ model: () => ({ toolCalls: [{ id: 'create', name: 'nc_create_text_file', arguments: { filename: 'Draft.md', content: 'Synthetic draft' } }] }) },
			identity, { cancelProposal: vi.fn(async () => {}) })
		try {
			await host.signIn('alice', 'test'); host.offerSelection(await host.selection.select(2))
			const work = host.send('Create a draft'); const part = await pending(host)
			await host.stopEverything.stop(); await work
			expect(files.cancelProposal).toHaveBeenCalledWith('proposal')
			expect(part.isPending).toBe(false)
			expect(host.chat.isBusy).toBe(false)
			expect(files.commit).not.toHaveBeenCalled()
			expect(host.stopEverything.resultText).not.toBe('')
		} finally { host.stop(); await host.dispose() }
	})
	it('keeps the record of tool calls in memory and opens no browser database for it', async () => {
		const opened: string[] = []
		vi.stubGlobal('indexedDB', { open: (name: string) => { opened.push(name); throw new DOMException('Not in this test.', 'SecurityError') } })
		let round = 0
		const { host } = fixture({ model: () => ++round === 1 ? { toolCalls: [{ id: 'read', name: 'nc_get_selected_file', arguments: {} }] } : { text: 'Done.' } })
		try {
			await host.signIn('alice', 'test'); host.offerSelection(await host.selection.select(2))
			await host.send('What is selected?')
			expect(opened.filter(name => name.includes('audit'))).toEqual([])
			const audit = (host.agent as unknown as { audit: unknown }).audit
			expect(audit).toBeInstanceOf(MemoryAuditLog)
			expect((audit as MemoryAuditLog).entries.map(entry => entry.tool)).toContain('nc_get_selected_file')
		} finally { host.stop(); await host.dispose(); vi.unstubAllGlobals() }
	})
	it('marks an answer copied out of the chat with a record the server signed, from its hash only', async () => {
		const { host, gateway } = fixture()
		try {
			await host.signIn('alice', 'test')
			const copy = await host.agent.markCopy({ text: 'A synthetic answer.', html: '<p>A synthetic answer.</p>' })
			expect(copy.marked).toBe(true)
			expect(copy.recordId).toBeTruthy()
			expect(copy.html).toContain(copy.recordId!)
			expect(copy.text).toBe('A synthetic answer.')
			expect(gateway.provenanceRecords.map(record => record.signed.record.id)).toContain(copy.recordId)
			const sent = gateway.requests.filter(r => r.path.endsWith('/provenance/records')).map(r => JSON.stringify(r.body))
			expect(sent).toHaveLength(1)
			expect(sent[0]).not.toContain('synthetic answer')
		} finally { await host.dispose() }
	})
	it('revokes its cloud sign-in after native disposal has stopped content-bearing requests', async () => {
		const { host, gateway, files } = fixture()
		await host.signIn('alice', 'test')
		vi.mocked(files.assertIdentity).mockRejectedValue(new Error('Native session changed'))
		await host.dispose()
		expect(gateway.requests.filter(r => r.path === '/api/agent-tokens/revoke')).toHaveLength(1)
		expect(gateway.modelCalls).toHaveLength(0)
	})
	it('approves final native-marked bytes and computes the shared reader digest before any commit', async () => {
		const content = '# Native draft\n\nSynthetic content.'
		const marked = withFrontMatter(content, '5a0e2b5c-7f3e-4a51-9a40-6a1c55e1d3f1')
		let round = 0
		const { host, files, gateway } = fixture({ model: () => ++round === 1
			? { toolCalls: [{ id: 'create', name: 'nc_create_text_file', arguments: { filename: 'Draft.md', content } }] }
			: { text: 'Completed.' } }, { ...identity, fileProvenanceEnabled: true }, {
			propose: vi.fn(async (contextId, args) => ({ ...args, id: 'marked-proposal', contextId, target: '/Draft.md', before: null,
				content: marked, originalContent: args.content, editKind: 'generated' })),
			commit: vi.fn(async () => ({ operation: 'create', file: { ...selected.file, id: 3, name: 'Draft.md' }, provenance: {
				metadata: JSON.stringify({ record: { id: '5a0e2b5c-7f3e-4a51-9a40-6a1c55e1d3f1' }, signature: 'native-only-signature' }),
				frontMatter: marked, recordId: '5a0e2b5c-7f3e-4a51-9a40-6a1c55e1d3f1', exempt: null,
			} })),
		})
		try {
			await host.signIn('alice', 'test')
			host.offerSelection(await host.selection.select(2))
			const work = host.send('Create the native draft')
			const part = await pending(host)
			expect(part.request.arguments.content).toBe(marked)
			expect(files.propose).toHaveBeenCalledWith(selected.id, expect.objectContaining({ content, readerTextSha256: await readerTextSha256(content, 'text/markdown') }), expect.any(AbortSignal))
			expect(files.commit).not.toHaveBeenCalled()
			await host.approve(part); await work
			expect(files.commit).toHaveBeenCalledWith('marked-proposal', expect.any(AbortSignal))
			const messages = JSON.stringify(gateway.modelCalls.at(-1)?.messages)
			expect(messages).toContain('nextcloud-metadata')
			expect(messages).not.toContain('native-only-signature')
		} finally { host.stop(); await host.dispose() }
	})
	it.each([true, false])('routes an included Whiteboard through its governed native capability (available=%s)', async available => {
		const policy: ScopePolicy = { version: 'native-board-context', gatewayOrigin: identity.serverUrl, sources: [{ kind: 'user-files' }], destinations: [], tools: ['nc_whiteboard_read'], sourceClasses: ['standard'], downstreamPolicy: 'unverified', destinationAudiences: [], movements: [], developmentLoopback: true }
		const file = { ...selected.file, name: 'Canvas.whiteboard', path: '/Canvas.whiteboard', mime: 'application/x-whiteboard' }
		const resource = { provider: 'whiteboard' as const, collectionId: 'personal', resourceId: 'nextcloud:whiteboard:MQ', revision: 'scene-v1', policyVersion: 'board-policy', sourceClass: 'standard' as const, audience: 'private' as const, filename: '{"path":"nextcloud:files:2"}' }
		const contentRead = vi.fn(async () => ({ scene: { elements: [] }, storedOnly: true, resource }))
		const { host, files, gateway } = fixture({ agents: [{ slug: 'nc', version: 1, contract: [{ name: 'nc_whiteboard_read', risk: 'read' }] }], model: () => ({ text: 'Saved scene only.' }) },
			{ ...identity, development: true, providerReadsEnabled: true, scopePolicy: policy }, {
				select: vi.fn(async () => ({ ...selected, file })),
				scopeResource: vi.fn(async reference => ({ resourceId: reference, revision: file.etag, sourceClass: 'standard', audience: 'private', scope: policy.sources[0] })),
				nativeProviders: { capabilities: async () => available ? [{ provider: 'whiteboard', apiVersion: 'whiteboard-2.1.2-session', policyVersion: 'board-policy', enabled: true, tools: ['nc_whiteboard_read'], conflict: 'read-only', history: 'audit-only', refresh: 'manual', canRead: true, canWrite: false }] : [], contentRead,
					calendarCollections: vi.fn(), calendarEvents: vi.fn(), calendarEvent: vi.fn(), calendarPropose: vi.fn(), calendarApprove: vi.fn(), calendarCommit: vi.fn() },
			})
		try {
			await host.signIn('alice', 'test')
			host.offerSelection(await host.selection.select(2))
			await host.send('Summarize the saved board')
			expect(files.read).not.toHaveBeenCalled()
			if (available) {
				expect(contentRead).toHaveBeenCalledWith('whiteboard', { path: 'nextcloud:files:2' }, expect.any(AbortSignal))
				expect(JSON.stringify(gateway.modelCalls.at(-1)?.messages)).toContain('storedOnly')
			} else expect(contentRead).not.toHaveBeenCalled()
		} finally { host.stop(); await host.dispose() }
	})
	it('refuses a pending text commit if its earlier share source loses inspection permission', async () => {
		const policy: ScopePolicy = { version: 'native-shares-write', sources: [{ kind: 'file', resourceId: 'nextcloud:files:2' }], destinations: [{ kind: 'user-files' }],
			tools: ['nc_files_shares_read', 'nc_create_text_file'], sourceClasses: ['standard'], gatewayOrigin: identity.serverUrl, downstreamPolicy: 'unverified',
			destinationAudiences: ['private'], movements: [{ source: 'standard', destination: 'private' }], developmentLoopback: true }
		let round = 0
		let canInspect = true
		const { host, files } = fixture({ agents: [{ slug: 'nc', version: 1, contract: [{ name: 'nc_files_shares_read', risk: 'read' }, { name: 'nc_create_text_file', risk: 'destructive' }] }],
			model: () => ++round === 1 ? { toolCalls: [{ id: 'shares', name: 'nc_files_shares_read', arguments: { resource_id: 'nextcloud:files:2' } }] }
				: round === 2 ? { toolCalls: [{ id: 'summary', name: 'nc_create_text_file', arguments: { filename: 'sharing-summary.md', content: 'Partial share details' } }] } : { text: 'Stopped.' } },
		{ ...identity, filesReadsEnabled: true, scopePolicy: policy }, {
			scopeResource: vi.fn(async (reference, purpose) => {
				if (purpose === 'shares' && !canInspect) throw new Error('Share inspection revoked')
				return { resourceId: reference, revision: 'revision-1', sourceClass: 'standard', audience: 'private', scope: purpose === 'write' ? policy.destinations[0] : policy.sources[0] }
			}), organizeRead: vi.fn(), sharesRead: vi.fn(async () => ({ resourceId: 'nextcloud:files:2', etag: 'revision-1', shares: [], complete: false, coverage: 'Current-user shares only.' })),
		})
		await host.signIn('alice', 'test')
		host.offerSelection(await host.selection.select(2))
		const sending = host.send('Inspect sharing and save a private summary')
		const approval = await pending(host)
		canInspect = false
		await host.approve(approval)
		await sending
		expect(files.commit).not.toHaveBeenCalled()
		await host.dispose()
	})
	it('reauthorizes cached share inspection before every model replay even while file READ remains', async () => {
		const policy: ScopePolicy = {
			version: 'native-shares',
			sources: [{ kind: 'file', resourceId: 'nextcloud:files:2' }],
			destinations: [],
			tools: ['nc_files_shares_read'],
			sourceClasses: ['standard'],
			gatewayOrigin: identity.serverUrl,
			downstreamPolicy: 'unverified',
			destinationAudiences: ['private'],
			movements: [],
			developmentLoopback: true,
		}
		let round = 0
		let canInspect = true
		const scopeResource: NonNullable<FileApi['scopeResource']> = vi.fn(async (reference, purpose) => {
			if (purpose === 'shares' && !canInspect) throw new Error('Share inspection revoked')
			return { resourceId: reference, revision: 'revision-1', sourceClass: 'standard', audience: 'private', scope: policy.sources[0] }
		})
		const { host, files, gateway } = fixture({
			agents: [{ slug: 'nc', version: 1, contract: [{ name: 'nc_files_shares_read', risk: 'read' }] }],
			model: () => ++round === 1 ? { toolCalls: [{ id: 'shares', name: 'nc_files_shares_read', arguments: { resource_id: 'nextcloud:files:2' } }] } : { text: 'Partial share metadata.' },
		},
		{ ...identity, filesReadsEnabled: true, scopePolicy: policy }, {
			scopeResource,
			organizeRead: vi.fn(),
			sharesRead: vi.fn(async () => ({ resourceId: 'nextcloud:files:2', etag: 'revision-1', shares: [], complete: false, coverage: 'Current-user shares only.' })),
		})
		await host.signIn('alice', 'test')
		host.offerSelection(await host.selection.select(2))
		await host.send('Inspect this file sharing')
		expect(files.sharesRead).toHaveBeenCalledOnce()
		expect(gateway.modelCalls).toHaveLength(2)
		canInspect = false
		await expect(host.send('Continue with those share details', false)).rejects.toThrow('Share inspection revoked')
		expect(gateway.modelCalls).toHaveLength(2)
		await host.dispose()
	})
	it('Stop aborts native egress scope checks before a model request is sent', async () => {
		const policy: ScopePolicy = {
			version: 'native-1',
			sources: [{ kind: 'file', resourceId: 'nextcloud:files:2' }],
			destinations: [],
			tools: ['nc_read_file'],
			sourceClasses: ['standard'],
			gatewayOrigin: identity.serverUrl,
			downstreamPolicy: 'unverified',
			destinationAudiences: ['private'],
			movements: [],
			developmentLoopback: true,
		}
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
		const policy: ScopePolicy = {
			version: 'native-1',
			sources: [{ kind: 'file', resourceId: 'nextcloud:files:2' }],
			destinations: [],
			tools: ['nc_read_file'],
			sourceClasses: ['standard'],
			gatewayOrigin: identity.serverUrl,
			downstreamPolicy: 'unverified',
			destinationAudiences: ['private'],
			movements: [],
			developmentLoopback: true,
		}
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
		const policy: ScopePolicy = {
			version: 'native-1',
			sources: [{ kind: 'file', resourceId: 'nextcloud:files:2' }],
			destinations: [],
			tools: ['nc_read_file'],
			sourceClasses: ['standard'],
			gatewayOrigin: identity.serverUrl,
			downstreamPolicy: 'unverified',
			destinationAudiences: ['private'],
			movements: [],
			developmentLoopback: true,
		}
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
		vi.stubGlobal('ResizeObserver', class {

			observe() {} disconnect() {}

		})
		let round = 0
		const { host, files } = fixture({
			agents: [{
				slug: 'nc',
				version: 1,
				contract: [
					...['nc_search_files', 'nc_get_file_metadata', 'nc_search_in_file', 'nc_search_content', 'nc_get_selected_file', 'nc_read_file', 'nc_list_folder'].map(name => ({ name, risk: 'read' as const })),
					{ name: 'nc_create_text_file', risk: 'write' }, { name: 'nc_update_text_file', risk: 'write' },
				],
			}],
			model: () => {
				round++
				if (round === 1) return { toolCalls: [{ name: 'nc_search_files', arguments: { query: 'source' }, id: 'search-call' }] }
				if (round === 3) return { toolCalls: [{ name: 'nc_search_in_file', arguments: { query: 'termination' }, id: 'passage-call' }] }
				if (round === 5) return { toolCalls: [{ name: 'nc_create_text_file', arguments: { filename: 'summary.md', content: '# Summary\n30 days notice.', resource_id: 'nextcloud:files:2' }, id: 'searched-write' }] }
				return { text: round === 2 ? 'Found the file.' : round === 4 ? '30 days written notice.' : 'Created summary.' }
			},
		})
		const source = { ...selected, file: { ...selected.file, url: new URL('/f/2', location.origin).href, modified: 1791797400 } }
		vi.mocked(files.select).mockResolvedValue(source)
		vi.mocked(files.read).mockResolvedValue({ file: source.file, text: 'Termination notice: 30 days written notice.', partial: false, totalCharacters: 42, processedCharacters: 42 })
		files.searchTransport = {
			plan: vi.fn(async () => ({ filters: { query: 'source', scope: 'all', limit: 20, sort_by: 'relevance', sort_direction: 'desc' }, queries: ['native query'], backend: 'webdav-search' })),
			dav: vi.fn(async () => '<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:response><d:propstat><d:prop><oc:fileid>2</oc:fileid></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>'),
			resolve: vi.fn(async () => [source.file]),
			capabilities: vi.fn(),
			content: vi.fn(),
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
		vi.stubGlobal('ResizeObserver', class {

			observe() {} disconnect() {}

		})
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
		vi.stubGlobal('ResizeObserver', class {

			observe() {} disconnect() {}

		})
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
			// The emergency stop sits in the panel's header, red, with no confirmation (release plan 8.11).
			expect(root.querySelector('.fdn-header .fdn-stop-everything-button')?.getAttribute('aria-label')).toBe('Stop everything')
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

	it('names this app in its links to fadenstack.com: ref nextcloud-panel in the footer, nextcloud-about in About', async () => {
		const { host } = fixture()
		await host.signIn('alice', 'test')
		try {
			const footer = new URL(host.chat.footerLink!.href)
			expect(footer.origin).toBe('https://fadenstack.com')
			expect(footer.searchParams.get('ref')).toBe('nextcloud-panel')
			const ours = host.chat.about.links.map(link => new URL(link.href)).filter(url => url.hostname.endsWith('fadenstack.com'))
			expect(ours.length).toBeGreaterThan(0)
			for (const url of ours) expect(url.searchParams.get('ref')).toBe('nextcloud-about')
		} finally { await host.dispose() }
	})

	it('the SDK gives the composer the AI notice as its description, so a screen reader reads it on focus (Art. 50(5))', async () => {
		vi.stubGlobal('ResizeObserver', class {

			observe() {} disconnect() {}

		})
		const { host, gateway } = fixture()
		await host.signIn('alice', 'test')
		const root = document.createElement('div')
		document.body.append(root)
		const app = createApp(AgentChat, { host: markRaw(host), context: selected })
		app.mount(root)
		const described = () => {
			const composer = root.querySelector<HTMLTextAreaElement>('.fdn-composer textarea')!
			const ids = (composer.getAttribute('aria-describedby') ?? '').split(' ').filter(Boolean)
			return ids.map(id => document.getElementById(id)?.textContent?.trim()).join(' ')
		}
		try {
			expect(described()).toBe(host.chat.strings.aiNotice)
			expect(described()).toContain('AI')
			await host.chat.setLanguage('de')
			await vi.waitFor(() => expect(described()).toBe('Antworten werden von einer KI erzeugt und können falsch sein. Prüfen Sie Wichtiges.'))
			// The off-switch replaces the composer's state; the link survives it, and comes back after Check again.
			gateway.turnOff()
			await host.chat.checkAgain()
			await nextTick()
			expect(host.chat.isAgentOff).toBe(true)
			expect(described()).toContain('KI')
			gateway.turnOn()
			await host.chat.checkAgain()
			await nextTick()
			expect(described()).toContain('KI')
			// One notice id per panel, never a duplicate in the description.
			expect(root.querySelector('.fdn-composer textarea')!.getAttribute('aria-describedby')!.split(' ')).toHaveLength(1)
		} finally {
			app.unmount(); root.remove(); await host.dispose(); vi.unstubAllGlobals()
		}
	})

	it('takes the panel preferences of this Nextcloud user with "Remove my data", and leaves other users alone', async () => {
		const { host } = fixture()
		localStorage.setItem('fadenstack_agent.alice.unmarkedNotice', '1')
		localStorage.setItem('fadenstack_agent.bob.unmarkedNotice', '1')
		localStorage.setItem('unrelated.key', 'kept')
		try {
			await host.signIn('alice', 'test')
			const removal = await host.agent.deleteMyData()
			expect(localStorage.getItem('fadenstack_agent.alice.unmarkedNotice')).toBeNull()
			expect(localStorage.getItem('fadenstack_agent.bob.unmarkedNotice')).toBe('1')
			expect(localStorage.getItem('unrelated.key')).toBe('kept')
			expect(removal.storesCleared).toBeGreaterThanOrEqual(1)
		} finally {
			localStorage.clear(); await host.dispose()
		}
	})

	it('stops the Stop everything result timer when the agent is disposed', async () => {
		const { host } = fixture()
		const dispose = vi.spyOn(host.stopEverything, 'dispose')
		await host.dispose()
		expect(dispose).toHaveBeenCalledOnce()
	})

	it('reflects an excluded implicit attachment without reading it or claiming it grounds a response', async () => {
		vi.stubGlobal('ResizeObserver', class {

			observe() {} disconnect() {}

		})
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
		vi.stubGlobal('ResizeObserver', class {

			observe() {} disconnect() {}

		})
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
		vi.stubGlobal('ResizeObserver', class {

			observe() {} disconnect() {}

		})
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
