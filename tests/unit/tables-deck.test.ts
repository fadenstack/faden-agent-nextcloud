import { describe, expect, it, vi } from 'vitest'
import {
	tablesTools,
	validateAddColumnArgs,
	validateAddRowArgs,
	validateUpdateRowArgs,
	validateDeleteRowArgs,
	sha256Hex,
	rowContent,
	TABLES_MUTATIONS,
} from '../../src/integrations/nextcloud/tables.ts'
import {
	deckTools,
	validateDeckPatchArgs,
	validateDeckCreateArgs,
	validateDeckMoveArgs,
	validateDeckAssignArgs,
	validateDeckLabelArgs,
	cardContent,
	DECK_MUTATIONS,
} from '../../src/integrations/nextcloud/deck.ts'
import { TABLES_CAPABILITIES, DECK_CAPABILITIES, fileCapability, FILE_CAPABILITIES } from '../../src/platform/catalog.ts'
import type {
	FileApi,
	FileContext,
	Identity,
	TableColumn,
	TableRow,
	TableSnapshot,
	DeckCard,
	DeckStack,
	DeckBoardSnapshot,
	TablesApi,
	DeckApi,
} from '../../src/integrations/nextcloud/types.ts'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { FakeGateway } from '@fadenstack/testing'
import { ApprovalPart } from '@fadenstack/ui'

const hostIdentity: Identity = {
	userId: 'alice',
	binding: 'binding-123',
	enabled: true,
	serverUrl: 'https://faden.test',
	agent: 'nc-tools',
	development: true,
	tablesEnabled: true,
	deckEnabled: true,
}

const mockContext: FileContext = {
	id: 'ctx-1',
	parentId: 1,
	expires: 9999999999,
	file: {
		id: 10,
		name: 'Lab',
		path: '/Lab',
		etag: 'dir-etag-1',
		mime: 'httpd/unix-directory',
		size: 0,
		folder: true,
		permissions: 31,
		url: 'https://cloud.test/f/10',
	},
}

const mockColumns: TableColumn[] = [
	{ id: 1, title: 'Item', type: 'text', subtype: 'line' },
	{ id: 2, title: 'Count', type: 'number', subtype: '' },
]

const mockRows: TableRow[] = [
	{ id: 10, tableId: 1, data: [{ columnId: 1, value: 'Apples' }, { columnId: 2, value: 5 }], lastEditBy: 'alice', lastEditAt: 12345 },
]

const mockCard: DeckCard = {
	id: 50,
	title: 'Setup Server',
	description: 'Configure and start server',
	type: 'plain',
	order: 0,
	owner: { uid: 'alice' },
	stackId: 5,
	duedate: '2026-10-30T10:00:00.000Z',
	archived: false,
	done: null,
	assignedUsers: [],
	labels: [{ id: 1, title: 'High', color: 'ff0000' }],
}

const mockStack: DeckStack = {
	id: 5,
	title: 'Backlog',
	cards: [mockCard],
}

const mockBoardSnapshot: DeckBoardSnapshot = {
	id: 2,
	board: {
		id: 2,
		title: 'Dev Board',
		color: '0082c9',
		labels: [{ id: 1, title: 'High', color: 'ff0000' }],
	},
	stacks: [mockStack, { id: 6, title: 'Done', cards: [] }],
}

async function createMockTablesApi(): Promise<TablesApi> {
	const schemaRevision = await sha256Hex(mockColumns)
	const snapshot: TableSnapshot = {
		table: { id: 1, title: 'Inventory' },
		columns: [...mockColumns],
		rows: [...mockRows],
		schemaRevision,
	}

	return {
		readTable: vi.fn(async () => ({ ...snapshot, columns: [...snapshot.columns], rows: [...snapshot.rows] })),
		addColumn: vi.fn(async (tableId, title, type) => ({ id: 3, tableId, title, type })),
		addRow: vi.fn(async (tableId, cells) => ({ id: 11, tableId, data: Object.entries(cells).map(([k, v]) => ({ columnId: Number(k), value: v })) })),
		updateRow: vi.fn(async (tableId, rowId, cells) => ({ id: rowId, tableId, data: Object.entries(cells).map(([k, v]) => ({ columnId: Number(k), value: v })) })),
		deleteRow: vi.fn(async () => true),
	}
}

function createMockDeckApi(): DeckApi {
	return {
		readBoard: vi.fn(async () => JSON.parse(JSON.stringify(mockBoardSnapshot))),
		patchCard: vi.fn(async (boardId, stackId, cardId, data) => ({ id: cardId, boardId, stackId, ...data })),
		createCard: vi.fn(async (boardId, stackId, title, description, duedate) => ({ id: 51, boardId, stackId, title, description, duedate })),
		moveCard: vi.fn(async (cardId, stackId, order) => ({ id: cardId, stackId, order })),
		assignCard: vi.fn(async (boardId, stackId, cardId, userId) => ({ cardId, userId })),
		labelCard: vi.fn(async (boardId, stackId, cardId, labelId) => ({ cardId, labelId })),
	}
}

async function createMockFilesApi(overrides: Partial<FileApi> = {}): Promise<FileApi> {
	const tables = await createMockTablesApi()
	const deck = createMockDeckApi()

	return {
		identity: vi.fn(async () => hostIdentity),
		assertIdentity: vi.fn(async () => {}),
		select: vi.fn(async () => mockContext),
		read: vi.fn(async () => ({ file: mockContext.file, text: '', partial: false, totalCharacters: 0, processedCharacters: 0 })),
		pdf: vi.fn(async () => new Uint8Array()),
		list: vi.fn(async () => ({ folder: mockContext.file, files: [], partial: false })),
		propose: vi.fn(async (_id, args) => ({ id: 'prop-1', contextId: 'ctx-1', ...args, target: '/target.txt', before: null })),
		approve: vi.fn(async () => {}),
		commit: vi.fn(async () => ({ operation: 'create', file: mockContext.file })),
		tables,
		deck,
		...overrides,
	}
}

function createGateway(options: ConstructorParameters<typeof FakeGateway>[0] = {}) {
	return new FakeGateway({
		agents: [{
			slug: 'nc-tools',
			version: 1,
			contract: [
				{ name: 'nc_tables_read', risk: 'read' },
				{ name: 'nc_tables_add_column', risk: 'destructive' },
				{ name: 'nc_tables_add_row', risk: 'destructive' },
				{ name: 'nc_tables_update_row', risk: 'destructive' },
				{ name: 'nc_tables_delete_row', risk: 'destructive' },
				{ name: 'nc_deck_read', risk: 'read' },
				{ name: 'nc_deck_patch', risk: 'destructive' },
				{ name: 'nc_deck_create', risk: 'destructive' },
				{ name: 'nc_deck_move', risk: 'destructive' },
				{ name: 'nc_deck_assign', risk: 'destructive' },
				{ name: 'nc_deck_label', risk: 'destructive' },
			],
		}],
		users: [{ email: 'alice', password: 'test', agents: ['nc-tools'] }],
		...options,
	})
}

describe('NC09: Tables and Deck capabilities and governance', () => {
	it('preserves base 9 FILE_CAPABILITIES while registering Tables and Deck tools with reversal held until integration', () => {
		expect(FILE_CAPABILITIES).toHaveLength(9)
		expect(TABLES_CAPABILITIES).toHaveLength(5)
		expect(DECK_CAPABILITIES).toHaveLength(6)

		expect(fileCapability('nc_tables_read')).toMatchObject({
			subsystem: 'tables',
			risk: 'read',
			individualApproval: false,
			reversal: 'not-applicable',
		})
		for (const name of ['nc_tables_add_column', 'nc_tables_add_row', 'nc_tables_update_row', 'nc_tables_delete_row']) {
			expect(fileCapability(name)).toMatchObject({
				subsystem: 'tables',
				risk: 'destructive',
				individualApproval: true,
				reversal: 'not-integrated',
			})
		}

		expect(fileCapability('nc_deck_read')).toMatchObject({
			subsystem: 'deck',
			risk: 'read',
			individualApproval: false,
			reversal: 'not-applicable',
		})
		for (const name of ['nc_deck_patch', 'nc_deck_create', 'nc_deck_move', 'nc_deck_assign', 'nc_deck_label']) {
			expect(fileCapability(name)).toMatchObject({
				subsystem: 'deck',
				risk: 'destructive',
				individualApproval: true,
				reversal: 'not-integrated',
			})
		}
	})
})

describe('NC09: Tables argument validation', () => {
	const validHash = 'a'.repeat(64)

	it('validates add column arguments', () => {
		expect(() => validateAddColumnArgs({ title: '', type: 'text', expectedSchemaRevision: validHash })).toThrow()
		expect(() => validateAddColumnArgs({ title: 'Col', type: 'invalid' as never, expectedSchemaRevision: validHash })).toThrow()
		expect(() => validateAddColumnArgs({ title: 'Col', type: 'text', expectedSchemaRevision: 'short' })).toThrow()
		expect(validateAddColumnArgs({ title: 'Status', type: 'text', expectedSchemaRevision: validHash })).toEqual({
			tableId: undefined,
			title: 'Status',
			type: 'text',
			expectedSchemaRevision: validHash,
		})
	})

	it('validates add and update row arguments', () => {
		expect(() => validateAddRowArgs({ cells: {}, expectedSchemaRevision: validHash })).toThrow('Use 1–12 targeted cells')
		expect(() => validateAddRowArgs({ cells: { 1: 'text' }, expectedSchemaRevision: 'bad' })).toThrow()
		expect(() => validateUpdateRowArgs({ rowId: 0, cells: { 1: 'text' }, expectedSchemaRevision: validHash, expectedRowRevision: validHash })).toThrow()
		expect(() => validateDeleteRowArgs({ rowId: 0, expectedSchemaRevision: validHash, expectedRowRevision: validHash })).toThrow()

		const validAdd = validateAddRowArgs({ tableId: 1, cells: { 1: 'Note', 2: 42 }, expectedSchemaRevision: validHash }, mockColumns)
		expect(validAdd.cells).toEqual({ 1: 'Note', 2: 42 })
	})
})

describe('NC09: Deck argument validation', () => {
	const validHash = 'b'.repeat(64)

	it('validates patch card arguments and normalizes duedate', () => {
		expect(() => validateDeckPatchArgs({ cardId: 0, expectedRevision: validHash, changes: { title: 'T' } })).toThrow()
		expect(() => validateDeckPatchArgs({ cardId: 1, expectedRevision: 'bad', changes: { title: 'T' } })).toThrow()
		expect(() => validateDeckPatchArgs({ cardId: 1, expectedRevision: validHash, changes: {} })).toThrow()
		expect(() => validateDeckPatchArgs({ cardId: 1, expectedRevision: validHash, changes: { invalidKey: 'val' } })).toThrow()

		const valid = validateDeckPatchArgs({
			cardId: 50,
			expectedRevision: validHash,
			changes: { title: 'New Title', duedate: '2026-10-29T12:00:00Z' },
		})
		expect(valid.changes.title).toBe('New Title')
		expect(valid.changes.duedate).toBe('2026-10-29T12:00:00.000Z')
	})

	it('validates create, move, assign and label arguments', () => {
		expect(() => validateDeckCreateArgs({ stackId: 0, title: 'T' })).toThrow()
		expect(() => validateDeckMoveArgs({ cardId: 1, stackId: 0, expectedRevision: validHash })).toThrow()
		expect(() => validateDeckAssignArgs({ cardId: 1, userId: '', expectedRevision: validHash })).toThrow()
		expect(() => validateDeckLabelArgs({ cardId: 1, labelId: 0, expectedRevision: validHash })).toThrow()

		expect(validateDeckCreateArgs({ stackId: 5, title: 'New Card', description: 'Desc' })).toEqual({
			boardId: undefined,
			stackId: 5,
			title: 'New Card',
			description: 'Desc',
			duedate: undefined,
		})
	})
})

describe('NC09: Tables tool execution and conflict handling', () => {
	it('reads table, hashes columns and rows, and validates 100-row limit', async () => {
		const files = await createMockFilesApi()
		const gateway = new FakeGateway()
		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)

		const result = await agent.readTable(1)
		expect(result.table.title).toBe('Inventory')
		expect(result.columns).toHaveLength(2)
		expect(result.rows).toHaveLength(1)
		expect(result.rows[0].cells).toEqual({ 1: 'Apples', 2: 5 })
		expect(result.schemaRevision).toMatch(/^[a-f0-9]{64}$/)
		expect(result.rows[0].revision).toMatch(/^[a-f0-9]{64}$/)
	})

	it('gates add_column through approval and executes upon allow', async () => {
		const files = await createMockFilesApi()
		const schemaRev = await sha256Hex(mockColumns)

		let callIndex = 0
		const gateway = createGateway({
			model: async () => (++callIndex === 1 ? {
				toolCalls: [
					{
						id: 'call-tbl-col',
						name: 'nc_tables_add_column',
						arguments: { tableId: 1, title: 'Category', type: 'text', expectedSchemaRevision: schemaRev },
					},
				],
			} : { text: 'Done' }),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'test')

		const sendPromise = agent.send('Add Category column', false)

		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})
		expect(part!.request.toolName).toBe('nc_tables_add_column')

		await agent.approve(part!)
		await sendPromise

		expect(files.tables!.addColumn).toHaveBeenCalledWith(1, 'Category', 'text', expect.anything())
		await agent.dispose()
	})

	it('detects concurrent schema conflict during approval and rejects commit without write', async () => {
		const files = await createMockFilesApi()
		const schemaRev = await sha256Hex(mockColumns)

		let callIndex = 0
		const gateway = createGateway({
			model: async () => (++callIndex === 1 ? {
				toolCalls: [
					{
						id: 'call-tbl-stale',
						name: 'nc_tables_add_row',
						arguments: { tableId: 1, cells: { 1: 'Oranges', 2: 10 }, expectedSchemaRevision: schemaRev },
					},
				],
			} : { text: 'Done' }),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'test')

		const sendPromise = agent.send('Add Oranges row', false)

		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		// Simulate schema changed on server while user was viewing approval prompt
		;(files.tables!.readTable as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
			table: { id: 1, title: 'Inventory' },
			columns: [...mockColumns, { id: 3, title: 'Price', type: 'number', subtype: '' }],
			rows: [...mockRows],
			schemaRevision: 'different-schema-rev',
		})

		await agent.approve(part!)
		await sendPromise
		expect(files.tables!.addRow).not.toHaveBeenCalled()
		await agent.dispose()
	})

	it('detects concurrent row conflict during update and aborts', async () => {
		const files = await createMockFilesApi()
		const schemaRev = await sha256Hex(mockColumns)
		const rowRev = await sha256Hex(rowContent(mockRows[0]))

		let callIndex = 0
		const gateway = createGateway({
			model: async () => (++callIndex === 1 ? {
				toolCalls: [
					{
						id: 'call-tbl-row',
						name: 'nc_tables_update_row',
						arguments: { tableId: 1, rowId: 10, cells: { 2: 12 }, expectedSchemaRevision: schemaRev, expectedRowRevision: rowRev },
					},
				],
			} : { text: 'Done' }),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'test')

		const sendPromise = agent.send('Update count', false)

		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		// Simulate row edited concurrently
		const editedRow: TableRow = { ...mockRows[0], data: [{ columnId: 1, value: 'Apples' }, { columnId: 2, value: 99 }] }
		;(files.tables!.readTable as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
			table: { id: 1, title: 'Inventory' },
			columns: mockColumns,
			rows: [editedRow],
			schemaRevision: schemaRev,
		})

		await agent.approve(part!)
		await sendPromise
		expect(files.tables!.updateRow).not.toHaveBeenCalled()
		await agent.dispose()
	})
})

describe('NC09: Deck tool execution and conflict handling', () => {
	it('reads board and stacks with computed SHA-256 card revisions', async () => {
		const files = await createMockFilesApi()
		const gateway = new FakeGateway()
		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)

		const result = (await agent.readDeck(2)) as { board: { title: string }; stacks: { cards: { revision: string; title: string }[] }[] }
		expect(result.board.title).toBe('Dev Board')
		expect(result.stacks).toHaveLength(2)
		expect(result.stacks[0].cards[0].title).toBe('Setup Server')
		expect(result.stacks[0].cards[0].revision).toMatch(/^[a-f0-9]{64}$/)
		await agent.dispose()
	})

	it('gates patch card through approval and executes with preflight validation', async () => {
		const files = await createMockFilesApi()
		const cardRev = await sha256Hex(cardContent(mockCard))

		let callIndex = 0
		const gateway = createGateway({
			model: async () => (++callIndex === 1 ? {
				toolCalls: [
					{
						id: 'call-deck-patch',
						name: 'nc_deck_patch',
						arguments: { boardId: 2, cardId: 50, expectedRevision: cardRev, changes: { title: 'Updated Server Setup' } },
					},
				],
			} : { text: 'Done' }),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'test')

		const sendPromise = agent.send('Update card title', false)

		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})
		expect(part!.request.toolName).toBe('nc_deck_patch')

		await agent.approve(part!)
		await sendPromise

		expect(files.deck!.patchCard).toHaveBeenCalledWith(2, 5, 50, expect.objectContaining({ title: 'Updated Server Setup' }), expect.anything())
		await agent.dispose()
	})

	it('executes card move to valid stack upon approval', async () => {
		const files = await createMockFilesApi()
		const cardRev = await sha256Hex(cardContent(mockCard))

		let callIndex = 0
		const gateway = createGateway({
			model: async () => (++callIndex === 1 ? {
				toolCalls: [
					{
						id: 'call-deck-move',
						name: 'nc_deck_move',
						arguments: { boardId: 2, cardId: 50, stackId: 6, expectedRevision: cardRev, order: 0 },
					},
				],
			} : { text: 'Done' }),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'test')

		const sendPromise = agent.send('Move card to Done stack', false)

		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		await agent.approve(part!)
		await sendPromise

		expect(files.deck!.moveCard).toHaveBeenCalledWith(50, 6, 0, expect.anything())
		await agent.dispose()
	})

	it('detects concurrent card edit during approval and aborts without write', async () => {
		const files = await createMockFilesApi()
		const cardRev = await sha256Hex(cardContent(mockCard))

		let callIndex = 0
		const gateway = createGateway({
			model: async () => (++callIndex === 1 ? {
				toolCalls: [
					{
						id: 'call-deck-stale',
						name: 'nc_deck_assign',
						arguments: { boardId: 2, cardId: 50, userId: 'bob', expectedRevision: cardRev },
					},
				],
			} : { text: 'Done' }),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'test')

		const sendPromise = agent.send('Assign to Bob', false)

		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		// Server card changed
		const changedCard: DeckCard = { ...mockCard, title: 'Server Setup Changed Elsewhere' }
		;(files.deck!.readBoard as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
			...mockBoardSnapshot,
			stacks: [{ ...mockStack, cards: [changedCard] }, { id: 6, title: 'Done', cards: [] }],
		})

		await agent.approve(part!)
		await sendPromise
		expect(files.deck!.assignCard).not.toHaveBeenCalled()
		await agent.dispose()
	})

	it('directly throws conflict errors on stale schema, row, or card revisions', async () => {
		const files = await createMockFilesApi()
		const gateway = createGateway()
		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		const signal = new AbortController().signal

		// Stale schema in plan
		await expect(
			agent.planTablesMutation('nc_tables_add_column', { tableId: 1, title: 'Extra', type: 'text', expectedSchemaRevision: '0'.repeat(64) }, signal),
		).rejects.toThrow('The table schema changed. Read the table again.')

		// Stale row in plan
		const schemaRev = await sha256Hex(mockColumns)
		await expect(
			agent.planTablesMutation('nc_tables_update_row', { tableId: 1, rowId: 10, cells: { 2: 9 }, expectedSchemaRevision: schemaRev, expectedRowRevision: '0'.repeat(64) }, signal),
		).rejects.toThrow('The row changed. Read it again before proposing a write.')

		// Stale card in plan
		await expect(
			agent.planDeckMutation('nc_deck_patch', { boardId: 2, cardId: 50, expectedRevision: '0'.repeat(64), changes: { title: 'T' } }, signal),
		).rejects.toThrow('Card revision changed. Read again before proposing a write.')

		await agent.dispose()
	})

	it('refuses execution when apps are not available', async () => {
		const filesWithoutTables = await createMockFilesApi({ tables: undefined })
		const agent1 = new NextcloudAgent({ ...hostIdentity, tablesEnabled: true }, filesWithoutTables)
		await expect(agent1.readTable(1)).rejects.toThrow('Tables app is unavailable')

		const filesWithoutDeck = await createMockFilesApi({ deck: undefined })
		const agent2 = new NextcloudAgent({ ...hostIdentity, deckEnabled: true }, filesWithoutDeck)
		await expect(agent2.readDeck(2)).rejects.toThrow('Deck app is unavailable')
	})
})
