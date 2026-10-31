import { describe, expect, it, vi } from 'vitest'
import { ApprovalPart, ToolCallPart } from '@fadenstack/ui'
import { FakeGateway } from '@fadenstack/testing'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { WorkspaceCoordinator } from '../../src/workspace/coordinator.ts'
import type { FileApi, Identity, TableSnapshot, DeckBoardSnapshot } from '../../src/integrations/nextcloud/types.ts'
import type { NativeProvidersApi, StructuredProposal } from '../../src/providers/client.ts'
import type { ProviderResource } from '../../src/providers/scope.ts'
import type { ScopePolicy } from '../../src/platform/scope.ts'

const rev = 'a'.repeat(64)
const tools = ['nc_tables_read', 'nc_tables_add_column', 'nc_tables_add_row', 'nc_tables_update_row', 'nc_tables_delete_row', 'nc_deck_read', 'nc_deck_patch', 'nc_deck_create', 'nc_deck_move', 'nc_deck_assign', 'nc_deck_label']
const mutations = tools.filter(name => !name.endsWith('_read'))
const policy: ScopePolicy = { version: 'test', gatewayOrigin: 'https://faden.test', sources: [{ kind: 'user-files' }], destinations: [], tools,
	sourceClasses: ['standard'], downstreamPolicy: 'unverified', destinationAudiences: [], movements: [], developmentLoopback: true }
const identity: Identity = { userId: 'alice', binding: 'native-session', enabled: true, agent: 'structured', serverUrl: 'https://faden.test', development: true, providerReadsEnabled: true, scopePolicy: policy }
const resource = (provider: 'tables' | 'deck'): ProviderResource => ({ provider, resourceId: `nextcloud:${provider}:1`, collectionId: '1', revision: rev, policyVersion: 'native-policy', sourceClass: 'standard', audience: 'unknown' })
const argsFor = (name: string): Record<string, unknown> => name.startsWith('nc_tables_')
	? { tableId: 1, expectedSchemaRevision: rev, ...(name.endsWith('add_column') ? { title: 'Estimate', type: 'number' } : name.endsWith('add_row') ? { cells: { 1: 'Draft' } } : { rowId: 10, expectedRowRevision: rev, ...(name.endsWith('update_row') ? { cells: { 1: 'Revised' } } : {}) }) }
	: { boardId: 1, ...(name.endsWith('_create') ? { stackId: 2, title: 'Draft' } : { cardId: 10, expectedRevision: rev,
		...(name.endsWith('_patch') ? { changes: { title: 'Revised' } } : name.endsWith('_move') ? { stackId: 3, order: 0 } : name.endsWith('_assign') ? { userId: 'alice' } : { labelId: 4 }) }) }

async function flow(name: string, { enabled = true, writable = true, crossSource = false, unknown = false, refused = false } = {}) {
	const provider = name.startsWith('nc_tables_') ? 'tables' as const : 'deck' as const
	let current = resource(provider)
	const table = (): TableSnapshot => ({ table: { id: 1, title: 'Tasks' }, columns: [{ id: 1, title: 'Task', type: 'text', subtype: 'line' }],
		rows: [{ id: 10, tableId: 1, revision: rev, data: [{ columnId: 1, value: 'Draft' }] }], schemaRevision: rev, nativeResource: provider === 'tables' ? current : resource('tables') })
	const board = (): DeckBoardSnapshot => ({ id: 1, board: { id: 1, title: 'Review', color: '0082c9', labels: [{ id: 4, title: 'Review', color: '0082c9' }] },
		stacks: [{ id: 2, title: 'Draft', cards: [] }, { id: 3, title: 'Review', cards: [] }], nativeResource: current })
	const native: NativeProvidersApi = {
		capabilities: vi.fn(async () => enabled ? [{ provider, enabled: true, apiVersion: provider === 'tables' ? 'tables-2.4-api1' : 'deck-1.19-api1', policyVersion: 'native-policy', tools: writable ? tools.filter(x => x.startsWith('nc_' + provider)) : ['nc_' + provider + '_read'], conflict: writable ? 'native' : 'read-only', canRead: true, canWrite: writable, refresh: 'child-reload', history: 'audit-only' },
			...(crossSource ? [{ provider: 'tables' as const, enabled: true, apiVersion: 'tables-2.4-api1', policyVersion: 'native-policy', tools: ['nc_tables_read' as const], conflict: 'read-only' as const, canRead: true, canWrite: false, refresh: 'child-reload' as const, history: 'audit-only' as const }] : [])] : []),
		calendarCollections: vi.fn(async () => []), calendarEvents: vi.fn(async () => []), calendarEvent: vi.fn(), calendarPropose: vi.fn(), calendarApprove: vi.fn(), calendarCommit: vi.fn(),
		structuredRead: vi.fn(async family => ({ snapshot: family === 'tables' ? table() : board(), resource: family === provider ? current : resource(family) })),
		structuredPropose: vi.fn(async (toolName, args): Promise<StructuredProposal> => ({ id: 'b'.repeat(48), digest: 'c'.repeat(64), toolName, provider,
			target: provider + ' 1', payload: { ...args }, before: { title: 'Draft' }, resource: current, expires: Date.now() / 1000 + 600, history: 'audit-only', limitation: 'Native last-write-wins; audit only, no Undo.' })),
		structuredApprove: vi.fn(async () => ({ approved: true })), structuredCancel: vi.fn(async () => ({})),
		structuredCommit: vi.fn(async () => {
			if (refused) throw Object.assign(new Error('Native permission was revoked.'), { response: { status: 403, data: { outcome: 'not-dispatched', message: 'Native permission was revoked.' } } })
			if (unknown) throw new Error('Native receipt unavailable; inspect before retrying.')
			current = { ...current, revision: 'd'.repeat(64) }
			return { status: 'saved', outcome: 'verified', actionId: 'e'.repeat(32), provider, collectionId: 1, target: provider + ' 1', result: { id: 10 }, resource: current, history: 'audit-only', limitation: 'Native last-write-wins.' }
		}),
	}
	const files: FileApi = { identity: async () => identity, assertIdentity: async () => {}, select: vi.fn(), read: vi.fn(), list: vi.fn(), pdf: vi.fn(), propose: vi.fn(), approve: vi.fn(), commit: vi.fn(), nativeProviders: native,
		scopeResource: vi.fn(),
		tables: { readTable: vi.fn(async () => table()), addColumn: vi.fn(), addRow: vi.fn(), updateRow: vi.fn(), deleteRow: vi.fn() },
		deck: { readBoard: vi.fn(async () => board()), patchCard: vi.fn(), createCard: vi.fn(), moveCard: vi.fn(), assignCard: vi.fn(), labelCard: vi.fn() } }
	let round = 0
	const gateway = new FakeGateway({ serverUrl: identity.serverUrl, agents: [{ slug: identity.agent, version: 1, contract: tools.map(name => ({ name, risk: name.endsWith('_read') ? 'read' : 'destructive' })) }],
		users: [{ email: 'alice', password: 'test', agents: [identity.agent] }], model: () => ++round === 1
			? { toolCalls: [{ id: 'read', name: crossSource ? 'nc_tables_read' : `nc_${provider}_read`, arguments: provider === 'tables' || crossSource ? { tableId: 1 } : { boardId: 1 } }] }
			: round === 2 ? { toolCalls: [{ id: 'write', name, arguments: argsFor(name) }] } : { text: 'Finished.' } })
	const workspace = new WorkspaceCoordinator({ guard: { snapshot: () => ({ app: provider, key: provider + '/1', epoch: 1, editState: 'clean', reason: '' }), dispose: () => {} }, prepareProvider: vi.fn(async () => {}), refresh: vi.fn(async () => 'refreshed') })
	const agent = new NextcloudAgent(identity, files, gateway.fetch, workspace)
	await agent.signIn('alice', 'test')
	const send = agent.send('Update this native collection', false)
	return { agent, files, native, workspace, send }
}
async function pending(agent: NextcloudAgent): Promise<ApprovalPart> {
	let part: ApprovalPart | undefined
	await vi.waitFor(() => {
		part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart
		expect(part?.isPending, JSON.stringify(agent.chat.turns.map(t => [...t.parts].map(p => ({ type: p.constructor.name, ...Object.fromEntries(['state', 'text', 'name', 'error', 'message'].map(k => [k, (p as unknown as Record<string, unknown>)[k]])) }))))).toBe(true)
	})
	return part!
}

describe('Native Tables/Deck composition using the published SDK', () => {
	it.each(mutations)('binds individual native approval and one verified dispatch for %s', async name => {
		const run = await flow(name)
		try {
			const part = await pending(run.agent)
			expect(run.native.structuredCommit).not.toHaveBeenCalled()
			expect(part.request).toMatchObject({ canAllowAlways: false, canAllowForSession: false, arguments: { digest: 'c'.repeat(64), limitation: 'Native last-write-wins; audit only, no Undo.', nativeAudience: 'unknown' } })
			await run.agent.approve(part); await run.send
			expect(run.native.structuredApprove).toHaveBeenCalledTimes(1)
			expect(run.native.structuredCommit).toHaveBeenCalledTimes(1)
			expect(run.files.tables!.addColumn).not.toHaveBeenCalled()
			expect(run.files.deck!.createCard).not.toHaveBeenCalled()
			expect(run.workspace.state.latest).toMatchObject({ outcome: 'committed', view: 'refreshed' })
		} finally { run.agent.stop(); await run.agent.dispose() }
	})
	it.each(['deny', 'stop', 'payload-change', 'audience-change'])('dispatches zero effects after %s', async action => {
		const run = await flow('nc_tables_add_row')
		try {
			const part = await pending(run.agent)
			if (action === 'deny') await run.agent.deny(part)
			else if (action === 'stop') run.agent.stop()
			else { if (action === 'payload-change') part.request.arguments.content = { data: { 1: 'Tampered' } }; else part.request.arguments.nativeAudience = 'private'; await expect(run.agent.approve(part)).rejects.toThrow('proposal changed'); await run.agent.deny(part) }
			await run.send
			expect(run.native.structuredCommit).not.toHaveBeenCalled()
			expect(run.native.structuredCancel).toHaveBeenCalled()
		} finally { run.agent.stop(); await run.agent.dispose() }
	})
	it.each([{ enabled: false }, { writable: false }, { crossSource: true }])('refuses unavailable grants or cross-collection movement: %j', async options => {
		const run = await flow('nc_deck_create', options)
		try { await run.send.catch(() => {}); expect(run.native.structuredPropose).not.toHaveBeenCalled(); expect(run.native.structuredCommit).not.toHaveBeenCalled() }
		finally { run.agent.stop(); await run.agent.dispose() }
	})
	it('does not retry an uncertain native commitment or report success', async () => {
		const run = await flow('nc_tables_add_row', { unknown: true })
		try {
			await run.agent.approve(await pending(run.agent)); await run.send.catch(() => {})
			expect(run.native.structuredCommit).toHaveBeenCalledTimes(1)
			const part = run.agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ToolCallPart && p.name === 'nc_tables_add_row')
			expect(part).toMatchObject({ state: 'failed' })
		} finally { run.agent.stop(); await run.agent.dispose() }
	})
	it('retains a native not-dispatched refusal without inventing uncertainty or retrying', async () => {
		const run = await flow('nc_deck_create', { refused: true })
		try {
			await run.agent.approve(await pending(run.agent)); await run.send.catch(() => {})
			expect(run.native.structuredCommit).toHaveBeenCalledTimes(1)
			expect(run.workspace.state.latest).toMatchObject({ outcome: 'not_dispatched', view: 'unchanged', message: 'Native permission was revoked.' })
		} finally { run.agent.stop(); await run.agent.dispose() }
	})
})
