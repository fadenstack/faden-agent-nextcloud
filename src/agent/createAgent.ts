import { FadenstackClient, AgentTokenProvider, MemoryTokenStore, loginWithPassword, type FetchLike } from '@fadenstack/client'
import { FadenstackAgent, MemorySessionStore, MemoryApprovalStore, StageOrder, type ToolCallContext, type MessageAttachment } from '@fadenstack/agents'
import { AgentChatViewModel, ApprovalBroker, AttachmentViewModel, type ApprovalPart, type PreferenceStore } from '@fadenstack/ui'
import { SelectedContext } from '../integrations/nextcloud/context.ts'
import { nextcloudLanguages } from './strings.ts'
import { APP_UI } from './appUi.ts'
import { nextcloudTools, WRITE_TOOLS, writeArguments } from '../integrations/nextcloud/tools.ts'
import type { FileApi, FileContext, Identity, Proposal, WriteArguments, SearchViewResults } from '../integrations/nextcloud/types.ts'
import { NextcloudSearchService, fileResource, resourceId } from '../integrations/nextcloud/search.ts'
import type { MutationBinding, WorkspaceCoordinator } from '../workspace/coordinator.ts'
import { TurnScope, type ScopeIdentity } from '../platform/scope.ts'
import type { ToolRisk } from '../platform/catalog.ts'
import { SourceLedger } from '../platform/sourceLedger.ts'

export class NextcloudAgent {

	readonly selection: SelectedContext
	readonly discovery: NextcloudSearchService
	readonly tokens: AgentTokenProvider
	readonly agent: FadenstackAgent
	readonly chat: AgentChatViewModel
	readonly approvals = new ApprovalBroker()
	readonly #stop = new AbortController()
	readonly #proposals = new Map<string, Proposal>()
	readonly #proposalViews = new Map<string, MutationBinding>()
	readonly #proposalResources = new Map<string, unknown>()
	readonly #proposalParents = new Map<string, number>()
	readonly #toolResults = new Map<string, SearchViewResults>()
	readonly #sessionSources = new Map<string, SourceLedger>()
	#sources = new SourceLedger()
	#scope: TurnScope | undefined
	#active: FileContext | undefined
	#closed = false
	#writeBlocked = false
	#include: boolean | undefined
	#prepareError: unknown
	#attachment: AttachmentViewModel | undefined
	readonly #fetch: FetchLike
	readonly #developmentHttp: boolean

	constructor(readonly config: Identity, readonly files: FileApi, fetchImpl: FetchLike = globalThis.fetch.bind(globalThis), readonly workspace?: WorkspaceCoordinator) {
		const url = new URL(config.serverUrl)
		this.#developmentHttp = config.development && url.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)
		this.selection = new SelectedContext(files)
		this.discovery = new NextcloudSearchService(files)
		this.#fetch = async (input, init) => {
			this.#stop.signal.throwIfAborted()
			if (new URL(String(input)).origin !== new URL(config.serverUrl).origin) throw new Error('The request origin is not approved.')
			await files.assertIdentity()
			this.#scope?.assertCurrent()
			// Only data-bearing model requests replay native content. Sign-out and minimal reports
			// must remain possible after a source is revoked. Reauthorize before every replay.
			if (new URL(String(input)).pathname.endsWith('/chat/completions') && config.scopePolicy) {
				this.#sources.assertProcessing(config.scopePolicy)
				if (!files.scopeResource) throw new Error('The native scope service is unavailable.')
				for (const reference of this.#sources.references) this.#sources.record(await files.scopeResource(reference, 'read', this.#stop.signal))
				this.#sources.assertProcessing(config.scopePolicy)
			}
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
			.addChatStage(StageOrder.custom, inner => ({
				stream: (messages, options = {}) => {
					const available = this.#scope?.offeredTools
					return inner.stream(messages, available ? { ...options, tools: options.tools?.filter(tool => available.some(name => name === tool.name)) } : options)
				},
			}))
			.addToolStage(StageOrder.custom, {
				invoke: async (invocation, next) => {
					this.#scope?.beginTool(invocation.tool.name)
					await files.assertIdentity()
					const result = await next(invocation)
					try { this.#scope?.budget.consumeResult(result, resultRecords(result)) } catch (error) { if (WRITE_TOOLS.has(invocation.tool.name)) this.#writeBlocked = true; throw error }
					return result
				},
			})
			.useSessionStore(new MemorySessionStore()).useApprovalStore(new MemoryApprovalStore())
			.useApprovals({
				request: async (request, signal) => {
					if (WRITE_TOOLS.has(request.toolName) && request.source === 'host') {
						if (this.#writeBlocked) throw new Error('A previous change did not complete. Start a new request after inspecting the resource.')
						const binding = this.workspace?.prepare('files')
						const target = await this.writeContext(request.toolName, request.arguments.resource_id, signal)
						if (this.#scope) {
							const destination = await this.#scope.bindDestination(`nextcloud:files:${request.toolName === 'nc_create_text_file' ? target.parentId : target.file.id}`, signal)
							const sources = await Promise.all(this.#sources.references.map(reference => this.#scope!.authorize(reference, 'read', signal)))
							await this.#scope.assertMovement(sources, destination, signal)
							this.#sources.assertMovement(this.#scope.policy, destination.audience)
						}
						const proposal = await files.propose(target.id, writeArguments(request.toolName, request.arguments), signal)
						this.#proposals.set(request.callId, proposal)
						this.#proposalParents.set(request.callId, target.parentId)
						this.#proposalResources.set(request.callId, request.arguments.resource_id)
						if (binding) this.#proposalViews.set(request.callId, binding)
						request = {
							...request,
							canAllowAlways: false,
							canAllowForSession: false,
							arguments: { ...request.arguments, proposalId: proposal.id, target: proposal.target, before: proposal.before, content: proposal.content },
						}
					}
					const result = await this.approvals.request(request, signal)
					if (!result.approved) { this.#proposals.delete(request.callId); this.#proposalViews.delete(request.callId); this.#proposalResources.delete(request.callId); this.#proposalParents.delete(request.callId); if (WRITE_TOOLS.has(request.toolName)) this.#writeBlocked = true }
					return result
				},
			})
			// What the app brings, for the agent's Apps tab: its tools, the context it offers, and the greeting and
			// suggestions an administrator can take in, so the empty chat starts with ready questions.
			.describeApp({ name: 'Nextcloud Files', version: '0.1.2', context: [{ id: 'nextcloud:selected', title: 'User-included file or folder', kind: 'document' }], ui: { greeting: APP_UI.greeting, suggestions: [...APP_UI.suggestions] } })
			.build()
		const locale = config.locale ?? navigator.language
		this.chat = new AgentChatViewModel(this.agent, this.approvals, {
			locale,
			// The user picks the language in the panel's settings; Automatic is the Nextcloud user's language.
			automaticLanguage: locale,
			languages: nextcloudLanguages,
			preferences: userPreferences(config.userId),
			// Every message, from the composer, a suggestion or Retry, first checks the Nextcloud session and
			// takes the file context it includes; the turn's tools read that context until it ends.
			beforeSend: (_message, signal) => this.#prepare(signal),
			afterSend: () => this.#finish(),
		})
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

	/** Metadata for presentation only: tools still resolve and authorize their frozen turn context. */
	get turnContext(): FileContext | undefined { return this.#active }
	get selectionIncluded(): boolean { return this.#attachment?.sends ?? false }
	get contextAttachment(): AttachmentViewModel | undefined { return this.#attachment }

	async read(signal: AbortSignal, id?: string): Promise<unknown> {
		const selected = id ? undefined : this.context()
		await this.authorizeSource(id ?? `nextcloud:files:${selected!.file.id}`, signal)
		const result = await this.discovery.read(id ?? `nextcloud:files:${selected!.file.id}`, signal, selected)
		return result
	}

	async list(signal: AbortSignal): Promise<unknown> {
		const result = await this.files.list(this.context().id, signal)
		for (const file of [result.folder, ...result.files]) await this.rememberSource(`nextcloud:files:${file.id}`, signal)
		return result
	}

	async search(filters: Parameters<NextcloudSearchService['search']>[0], signal: AbortSignal) {
		const result = await this.discovery.search(this.#scope ? { ...filters, scope: this.#scope.searchScope(filters.scope ?? 'all') } : filters, signal)
		for (const file of result.results) await this.rememberSource(file.resource_id, signal)
		return result
	}

	async metadata(id: unknown, signal: AbortSignal) {
		const selected = id === undefined ? this.context() : undefined
		await this.authorizeSource(String(id ?? `nextcloud:files:${selected!.file.id}`), signal, 'metadata')
		return fileResource(await this.discovery.metadata(id ?? `nextcloud:files:${selected!.file.id}`, signal, selected))
	}

	async inFile(args: Readonly<Record<string, unknown>>, signal: AbortSignal) {
		const selected = args.resource_id === undefined ? this.context() : undefined
		const id = args.resource_id ?? `nextcloud:files:${selected!.file.id}`
		resourceId(id)
		await this.authorizeSource(String(id), signal)
		return this.discovery.inFile(String(id), args.query, args.match_mode ?? 'exact_phrase', args.max_matches ?? 10, signal, selected)
	}

	async contentSearch(args: Readonly<Record<string, unknown>>, signal: AbortSignal) {
		const result = await this.discovery.content(args.query, args.limit ?? 20, args.page ?? 1, signal)
		for (const file of result.results) await this.rememberSource(file.resource_id, signal)
		return result
	}

	private async rememberSource(reference: string, signal: AbortSignal): Promise<void> {
		if (!this.#scope) return
		this.#sources.record(await this.#scope.bindSource(reference, signal))
	}

	private async authorizeSource(reference: string, signal: AbortSignal, purpose: 'read' | 'metadata' = 'read'): Promise<void> {
		if (!this.#scope) return
		this.#sources.record(await this.#scope.authorize(reference, purpose, signal))
	}

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

	async write(args: WriteArguments, call: ToolCallContext, resourceId?: unknown): Promise<unknown> {
		if (this.#writeBlocked) throw new Error('Start a new request before proposing another change.')
		const proposal = this.#proposals.get(call.callId)
		if (!proposal) throw new Error('No individually approved proposal exists for this tool call.')
		// A module may transform arguments after the SDK requested approval. Never silently
		// substitute those values, or dispatch content different from the reviewed proposal.
		if (resourceId !== this.#proposalResources.get(call.callId) || args.operation !== proposal.operation || args.content !== proposal.content || (args.filename ?? '') !== (proposal.operation === 'create' ? proposal.filename ?? '' : '') || (args.expectedEtag ?? '') !== (proposal.expectedEtag ?? '')) {
			this.#writeBlocked = true
			this.#proposals.delete(call.callId)
			this.#proposalViews.delete(call.callId)
			this.#proposalResources.delete(call.callId)
			this.#proposalParents.delete(call.callId)
			throw new Error('The proposed arguments changed after approval. Review a new proposal.')
		}
		this.#proposals.delete(call.callId)
		const binding = this.#proposalViews.get(call.callId)
		const parentId = this.#proposalParents.get(call.callId)
		this.#proposalViews.delete(call.callId)
		this.#proposalResources.delete(call.callId)
		this.#proposalParents.delete(call.callId)
		await this.files.assertIdentity()
		call.signal.throwIfAborted()
		const generation = binding ? this.workspace!.begin(call.callId, binding) : undefined
		try {
			const result = await this.files.commit(proposal.id, call.signal)
			const activity = binding && generation !== undefined ? await this.workspace!.committed(call.callId, generation, binding, result, call.signal) : undefined
			const current = this.selection.snapshot()
			if (!call.signal.aborted && current && (current.file.id === result.file.id || current.file.id === parentId)) {
				try {
					const updated = await this.selection.select(current.file.id, call.signal)
					if (updated) {
						this.offerSelection(updated)
						if (this.#active?.file.folder && this.#active.file.id === updated.file.id && this.#active.file.path === updated.file.path) this.#active = updated
					}
				} catch { this.offerSelection() }
			}
			return activity ? { ...result, view: activity.view } : result
		} catch (error) {
			this.#writeBlocked = true
			if (generation !== undefined) this.workspace!.failed(call.callId, generation, proposal.filename ?? '', error)
			throw error
		}
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
			implicit: true,
			resolve: signal => this.resolveAttachment(signal),
		})
		this.chat.addAttachment(this.#attachment)
	}

	async resolveAttachment(signal: AbortSignal): Promise<MessageAttachment> {
		const selected = this.context()
		try {
			const result = selected.file.folder ? await this.list(signal) : await this.read(signal)
			this.#scope?.budget.consumeResult(result, resultRecords(result))
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

	/**
	 * Sends a message, with the selected file when `include` (by default: when its chip is on). Throws what stopped
	 * it before it went (a changed Nextcloud session, no file selected); the composer sends through the same steps.
	 */
	async send(text: string, include?: boolean): Promise<void> {
		if (this.#closed) throw new Error('This connection has ended. Sign in again.')
		if (this.chat.isBusy || !text.trim()) return
		this.#include = include
		this.#prepareError = undefined
		try { await this.chat.send(text) } finally { this.#include = undefined }
		if (this.#prepareError) throw this.#prepareError
	}

	/** Before a message goes: the Nextcloud session still holds, and the file context it includes is taken. */
	async #prepare(signal: AbortSignal): Promise<void> {
		const include = this.#include ?? this.#attachment?.sends ?? false
		this.#include = undefined
		try {
			if (this.#closed) throw new Error('This connection has ended. Sign in again.')
			this.#writeBlocked = false
			const stopped = AbortSignal.any([signal, this.#stop.signal])
			this.#active = include ? this.selection.snapshot() : undefined
			await this.files.assertIdentity()
			const session = this.chat.currentSessionId
			this.#sources = new SourceLedger(session ? this.#sessionSources.get(session) : undefined)
			this.#scope?.invalidate()
			this.#scope = undefined
			if (this.config.scopePolicy) {
				if (!this.files.scopeResource) throw new Error('The native scope service is unavailable.')
				const profile = this.agent.profile
				const frozen = JSON.stringify(profile)
				const identity: ScopeIdentity = {
					nextcloudOrigin: location.origin,
					nextcloudUser: this.config.userId,
					nextcloudBinding: this.config.binding,
					fadenstackOrigin: new URL(this.config.serverUrl).origin,
					fadenstackPrincipal: { kind: 'session', id: this.tokens.deviceId ?? '' },
					agent: profile.id,
					agentVersion: profile.version!,
					schemaVersion: 'nextcloud-files-v1',
					contractVersion: `${profile.id}:${profile.version}:${profile.instructionsSha256 ?? ''}`,
					policyVersion: this.config.scopePolicy.version,
				}
				const published = profile.hostToolContract.filter(tool => ['read', 'write', 'destructive'].includes(tool.risk ?? '')).map(tool => ({ name: tool.name, risk: tool.risk as ToolRisk }))
				this.#scope = new TurnScope(identity, this.config.scopePolicy, published,
					() => ({ ...identity, fadenstackPrincipal: { kind: 'session', id: this.tokens.deviceId ?? '' }, contractVersion: JSON.stringify(this.agent.profile) === frozen ? identity.contractVersion : 'changed' }),
					(reference, purpose, _scopes, innerSignal) => this.files.scopeResource!(reference, purpose, innerSignal))
				this.#sources.assertProcessing(this.#scope.policy)
				for (const reference of this.#sources.references) this.#sources.record(await this.#scope.bindSource(reference, stopped))
				if (this.#active) await this.rememberSource(`nextcloud:files:${this.#active.file.id}`, stopped)
			}
			stopped.throwIfAborted()
			if (include && !this.#active) throw new Error('Select a file first.')
			if (this.#active) this.discovery.remember(this.#active.file)
			if (this.#active && !this.#attachment) this.chat.addAttachment(new AttachmentViewModel({ title: this.#active.file.name, resolve: signal => this.resolveAttachment(signal) }))
		} catch (error) {
			this.#active = undefined
			this.#prepareError = error
			throw error
		}
	}

	/** After the turn: its file context and any proposals it left are gone. */
	#finish(): void {
		if (this.chat.currentSessionId) this.#sessionSources.set(this.chat.currentSessionId, new SourceLedger(this.#sources))
		this.#scope?.invalidate()
		this.#scope = undefined
		this.#active = undefined
		this.#proposals.clear()
		this.#proposalViews.clear()
		this.#proposalResources.clear()
		this.#proposalParents.clear()
	}

	stop(): void { this.chat.stop() }

	async dispose(): Promise<void> {
		if (this.#closed) return
		this.#closed = true
		this.#stop.abort()
		this.chat.stop()
		this.chat.dispose()
		this.selection.clear()
		this.discovery.clear()
		this.#toolResults.clear()
		this.#sources.clear()
		this.#sessionSources.clear()
		this.#scope?.invalidate()
		this.#active = undefined
		this.#proposals.clear()
		this.#proposalViews.clear()
		this.#proposalResources.clear()
		this.#proposalParents.clear()
		await this.tokens.signOut()
		await this.agent.dispose()
	}

}

function resultRecords(result: unknown): number {
	if (result && typeof result === 'object') {
		if ('results' in result && Array.isArray(result.results)) return result.results.length
		if ('files' in result && Array.isArray(result.files)) return result.files.length + 1
	}
	return 1
}

/** The language the user picked, in this browser, per Nextcloud user (a shared computer keeps each user's own). */
function userPreferences(userId: string): PreferenceStore {
	const key = (name: string) => `fadenstack_agent.${userId}.${name}`
	return {
		get: (name) => { try { return localStorage.getItem(key(name)) ?? undefined } catch { return undefined } },
		set: (name, value) => {
			try {
				if (value === undefined) localStorage.removeItem(key(name))
				else localStorage.setItem(key(name), value)
			} catch { /* storage refused (private mode): the pick holds for this page */ }
		},
	}
}
