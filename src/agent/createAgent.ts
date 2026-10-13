import { FadenstackClient, AgentTokenProvider, MemoryTokenStore, loginWithPassword, type FetchLike } from '@fadenstack/client'
import { FadenstackAgent, MemorySessionStore, MemoryApprovalStore, type ToolCallContext, type MessageAttachment } from '@fadenstack/agents'
import { AgentChatViewModel, ApprovalBroker, AttachmentViewModel, type ApprovalPart } from '@fadenstack/ui'
import { SelectedContext } from '../integrations/nextcloud/context.ts'
import { germanStrings } from './strings.ts'
import { nextcloudTools, WRITE_TOOLS, writeArguments } from '../integrations/nextcloud/tools.ts'
import type { FileApi, FileContext, Identity, Proposal, WriteArguments, SearchViewResults } from '../integrations/nextcloud/types.ts'
import { NextcloudSearchService, fileResource, resourceId } from '../integrations/nextcloud/search.ts'

export class NextcloudAgent {

	readonly selection: SelectedContext
	readonly discovery: NextcloudSearchService
	readonly tokens: AgentTokenProvider
	readonly agent: FadenstackAgent
	readonly chat: AgentChatViewModel
	readonly approvals = new ApprovalBroker()
	readonly #stop = new AbortController()
	readonly #proposals = new Map<string, Proposal>()
	readonly #toolResults = new Map<string, SearchViewResults>()
	#active: FileContext | undefined
	#sending = false
	#closed = false
	#preparingStop: AbortController | undefined
	#attachment: AttachmentViewModel | undefined
	readonly #fetch: FetchLike
	readonly #developmentHttp: boolean

	constructor(readonly config: Identity, readonly files: FileApi, fetchImpl: FetchLike = globalThis.fetch.bind(globalThis)) {
		const url = new URL(config.serverUrl)
		this.#developmentHttp = config.development && url.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)
		this.selection = new SelectedContext(files)
		this.discovery = new NextcloudSearchService(files)
		this.#fetch = async (input, init) => {
			this.#stop.signal.throwIfAborted()
			if (new URL(String(input)).origin !== new URL(config.serverUrl).origin) throw new Error('The request origin is not approved.')
			await files.assertIdentity()
			this.#stop.signal.throwIfAborted()
			return fetchImpl(input, {
				...init,
				credentials: 'omit',
				redirect: 'error',
				signal: init?.signal ? AbortSignal.any([init.signal, this.#stop.signal]) : this.#stop.signal,
			})
		}
		this.tokens = new AgentTokenProvider({ apiUrl: config.serverUrl, agent: config.agent, store: new MemoryTokenStore(), fetch: this.#fetch, allowInsecureHttp: this.#developmentHttp })
		const client = new FadenstackClient({ serverUrl: config.serverUrl, agent: config.agent, credentials: this.tokens, fetch: this.#fetch, allowInsecureHttp: this.#developmentHttp })
		this.agent = FadenstackAgent.createBuilder().useClient(client)
			.addTools(...nextcloudTools(this))
			.useSessionStore(new MemorySessionStore()).useApprovalStore(new MemoryApprovalStore())
			.useApprovals({
				request: async (request, signal) => {
					if (WRITE_TOOLS.has(request.toolName) && request.source === 'host') {
						const target = await this.writeContext(request.toolName, request.arguments.resource_id, signal)
						const proposal = await files.propose(target.id, writeArguments(request.toolName, request.arguments), signal)
						this.#proposals.set(request.callId, proposal)
						request = {
							...request,
							canAllowAlways: false,
							canAllowForSession: false,
							arguments: { ...request.arguments, proposalId: proposal.id, target: proposal.target, before: proposal.before, content: proposal.content },
						}
					}
					const result = await this.approvals.request(request, signal)
					if (!result.approved) this.#proposals.delete(request.callId)
					return result
				},
			})
			.describeApp({ name: 'Nextcloud Files', version: '0.1.0', context: [{ id: 'nextcloud:selected', title: 'User-included file or folder', kind: 'document' }] })
			.build()
		this.chat = new AgentChatViewModel(this.agent, this.approvals, { locale: navigator.language, strings: navigator.language.startsWith('de') ? germanStrings : undefined })
	}

	async signIn(email: string, password: string): Promise<void> {
		const login = await loginWithPassword({ apiUrl: this.config.serverUrl, email, password, fetch: this.#fetch, signal: this.#stop.signal, allowInsecureHttp: this.#developmentHttp })
		await this.tokens.signIn(login, 'Nextcloud Files', this.#stop.signal)
		await this.chat.initialize()
		if (!this.chat.isReady) throw new Error(this.chat.error || 'The agent could not be initialized.')
		if (this.agent.profile.draft || this.agent.profile.version === null) throw new Error('Use a published agent version for the Nextcloud integration.')
	}

	context(): FileContext {
		if (!this.#active || this.#closed) throw new Error('Include the selected file or folder in this request first.')
		return this.#active
	}

	async read(signal: AbortSignal, id?: string): Promise<unknown> {
		const selected = id ? undefined : this.context()
		const result = await this.discovery.read(id ?? `nextcloud:files:${selected!.file.id}`, signal, selected)
		return result
	}

	list(signal: AbortSignal): Promise<unknown> { return this.files.list(this.context().id, signal) }
	search(filters: Parameters<NextcloudSearchService['search']>[0], signal: AbortSignal) { return this.discovery.search(filters, signal) }
	async metadata(id: unknown, signal: AbortSignal) {
		const selected = id === undefined ? this.context() : undefined
		return fileResource(await this.discovery.metadata(id ?? `nextcloud:files:${selected!.file.id}`, signal, selected))
	}

	inFile(args: Readonly<Record<string, unknown>>, signal: AbortSignal) {
		const selected = args.resource_id === undefined ? this.context() : undefined
		const id = args.resource_id ?? `nextcloud:files:${selected!.file.id}`
		resourceId(id)
		return this.discovery.inFile(String(id), args.query, args.match_mode ?? 'exact_phrase', args.max_matches ?? 10, signal, selected)
	}

	contentSearch(args: Readonly<Record<string, unknown>>, signal: AbortSignal) { return this.discovery.content(args.query, args.limit ?? 20, args.page ?? 1, signal) }
	recordResult(callId: string, value: unknown): void {
		if (!value || typeof value !== 'object' || !('kind' in value) || !['nextcloud-search-results', 'nextcloud-document-search'].includes(String(value.kind))) return
		this.#toolResults.set(callId, value as SearchViewResults)
		while (this.#toolResults.size > 100) this.#toolResults.delete(this.#toolResults.keys().next().value!)
	}

	toolResult(callId: string): SearchViewResults | undefined { return this.#toolResults.get(callId) }
	async addSearchContext(id: string): Promise<void> {
		await this.discovery.metadata(id, this.#stop.signal)
		const context = await this.selection.select(resourceId(id))
		if (!context) throw new Error('The selection changed. Add the result again.')
		this.offerSelection(context)
		this.chat.requestComposerFocus()
	}

	private async writeContext(tool: string, id: unknown, signal: AbortSignal): Promise<FileContext> {
		const selected = id === undefined ? this.context() : undefined
		const key = id ?? `nextcloud:files:${selected!.file.id}`
		if (id === undefined) this.discovery.remember(selected!.file)
		await this.discovery.metadata(key, signal, selected)
		if (tool === 'nc_update_text_file') {
			const cache = this.discovery.cache.get(String(key))
			if (!cache || cache.context.expires * 1000 <= Date.now()) throw new Error('Read the current document before proposing an edit.')
			return cache.context
		}
		return selected ?? this.files.select(resourceId(key), signal)
	}

	async write(_args: WriteArguments, call: ToolCallContext): Promise<unknown> {
		const proposal = this.#proposals.get(call.callId)
		if (!proposal) throw new Error('No individually approved proposal exists for this tool call.')
		this.#proposals.delete(call.callId)
		await this.files.assertIdentity()
		call.signal.throwIfAborted()
		return this.files.commit(proposal.id, call.signal)
	}

	async approve(part: ApprovalPart): Promise<void> {
		const id = part.request.arguments.proposalId
		if (WRITE_TOOLS.has(part.toolName) && part.request.source === 'host') {
			const proposal = this.#proposals.get(part.request.callId)
			if (!proposal || proposal.id !== id || !part.isPending) throw new Error('This proposal is no longer pending.')
			await this.files.assertIdentity()
			await this.files.approve(proposal.id, this.#stop.signal)
		}
		part.allow()
	}

	offerSelection(context?: FileContext): void {
		if (this.#attachment) this.chat.removeAttachment(this.#attachment)
		this.#attachment = undefined
		if (!context) { this.selection.clear(); return }
		this.#attachment = new AttachmentViewModel({
			title: context.file.name,
			subtitle: context.file.path,
			implicit: true,
			resolve: signal => this.resolveAttachment(signal),
		})
		this.chat.addAttachment(this.#attachment)
	}

	async resolveAttachment(signal: AbortSignal): Promise<MessageAttachment> {
		const selected = this.context()
		try {
			const result = selected.file.folder ? await this.list(signal) : await this.read(signal)
			signal.throwIfAborted()
			return {
				title: selected.file.name,
				kind: 'document',
				source: 'Nextcloud',
				reference: selected.file.url,
				content: JSON.stringify({ source: selected.file, untrustedDocumentData: result }),
			}
		} catch (error) { this.#active = undefined; throw error }
	}

	async send(text: string, include = this.#attachment?.sends ?? false): Promise<void> {
		if (this.#closed) throw new Error('This connection has ended. Sign in again.')
		if (this.#sending || this.chat.isBusy || !text.trim()) return
		this.#sending = true
		this.#preparingStop = new AbortController()
		const signal = AbortSignal.any([this.#stop.signal, this.#preparingStop.signal])
		this.#active = include ? this.selection.snapshot() : undefined
		try {
			await this.files.assertIdentity()
			signal.throwIfAborted()
			if (include && !this.#active) throw new Error('Select a file first.')
			if (this.#active) this.discovery.remember(this.#active.file)
			if (this.#active && !this.#attachment) this.chat.addAttachment(new AttachmentViewModel({ title: this.#active.file.name, resolve: signal => this.resolveAttachment(signal) }))
			await this.chat.send(text)
		} finally {
			this.#active = undefined
			this.#proposals.clear()
			this.#sending = false
			this.#preparingStop = undefined
		}
	}

	stop(): void { this.#preparingStop?.abort(); this.chat.stop() }

	async dispose(): Promise<void> {
		if (this.#closed) return
		this.#closed = true
		this.#stop.abort()
		this.chat.stop()
		this.chat.dispose()
		this.selection.clear()
		this.discovery.clear()
		this.#toolResults.clear()
		this.#active = undefined
		this.#proposals.clear()
		await this.tokens.signOut()
		await this.agent.dispose()
	}

}
