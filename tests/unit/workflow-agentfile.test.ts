import { NO_TOOL_SERVICES } from '@fadenstack/agents'
import { describe, expect, it, vi } from 'vitest'
import {
	whiteboardTools,
	flowTools,
	windmillTools,
	n8nTools,
	agentfileTool,
	notesTools,
	bookmarksTools,
	WHITEBOARD_MUTATIONS,
	FLOW_MUTATIONS,
	WINDMILL_MUTATIONS,
	N8N_MUTATIONS,
	NOTES_MUTATIONS,
	BOOKMARKS_MUTATIONS,
	validateWhiteboardCreateArgs,
	validateFlowCreateDraftArgs,
	validateFlowUpdateRuleArgs,
	validateWindmillDraftArgs,
	validateWindmillRunArgs,
	validateN8nDraftArgs,
	validateN8nExecuteArgs,
	validateAgentfileReadArgs,
	validateNotesCreateArgs,
	validateBookmarksCreateArgs,
} from '../../src/integrations/nextcloud/workflowAgentfile.ts'
import {
	WHITEBOARD_CAPABILITIES,
	FLOW_CAPABILITIES,
	WINDMILL_CAPABILITIES,
	N8N_CAPABILITIES,
	AGENTFILE_CAPABILITIES,
	NOTES_CAPABILITIES,
	BOOKMARKS_CAPABILITIES,
	fileCapability,
} from '../../src/platform/catalog.ts'
import type {
	FileApi,
	FileContext,
	Identity,
	WhiteboardApi,
	FlowApi,
	WindmillApi,
	N8nApi,
	AgentfileApi,
	NotesApi,
	BookmarksApi,
} from '../../src/integrations/nextcloud/types.ts'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { FakeGateway } from '../helpers/gateway071.ts'
import { ApprovalPart } from '@fadenstack/ui'

const hostIdentity: Identity = {
	userId: 'alice',
	binding: 'binding-123',
	enabled: true,
	serverUrl: 'https://faden.test',
	agent: 'nc-tools',
	development: true,
	whiteboardEnabled: true,
	flowEnabled: true,
	windmillEnabled: true,
	n8nEnabled: true,
	agentfileEnabled: true,
	notesEnabled: true,
	bookmarksEnabled: true,
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

function createMockWhiteboardApi(): WhiteboardApi {
	return {
		readBoard: vi.fn(async (path) => ({ id: path, scene: { elements: [] } })),
		createBoard: vi.fn(async (path, name) => ({ path: `${path}/${name}`, created: true })),
	}
}

function createMockFlowApi(): FlowApi {
	return {
		readRules: vi.fn(async () => ({
			rules: [{ id: 1, description: 'Auto tag PDF', operation: 'tag', active: true }],
		})),
		createDraftRule: vi.fn(async (description, operation, _config) => ({ ruleId: 2, active: false })),
		updateRule: vi.fn(async (ruleId, active, deleteRule) => ({ ruleId, active: !!active, deleted: !!deleteRule })),
	}
}

function createMockWindmillApi(): WindmillApi {
	return {
		readFlows: vi.fn(async (_path) => ({ flows: [{ path: 'u/alice/backup' }], jobs: [] })),
		createDraftFlow: vi.fn(async (path, summary, _value) => ({ path, draft: true })),
		runFlow: vi.fn(async (path, _args) => ({ jobId: 'job-123', status: 'started' })),
	}
}

function createMockN8nApi(): N8nApi {
	return {
		readWorkflows: vi.fn(async () => ({ workflows: [{ id: 'w1', name: 'Sync' }], executions: [] })),
		createDraftWorkflow: vi.fn(async (name, _nodes, _connections) => ({ id: 'w2', active: false })),
		executeWorkflow: vi.fn(async (workflowId, _data) => ({ executionId: 'exec-456', status: 'running' })),
	}
}

function createMockAgentfileApi(): AgentfileApi {
	return {
		readManifest: vi.fn(async (_content) => ({
			name: 'TestAgent',
			version: '1.0.0',
			description: 'Test agent manifest',
			instructions: 'Do helpful tasks',
		})),
	}
}

function createMockNotesApi(): NotesApi {
	return {
		readNotes: vi.fn(async (_cat) => ({
			notes: [{ id: 10, title: 'Release Checklist', category: 'Dev', content: 'Tests passing' }],
		})),
		createNote: vi.fn(async (title, _content, _cat) => ({ noteId: 11, title })),
	}
}

function createMockBookmarksApi(): BookmarksApi {
	return {
		searchBookmarks: vi.fn(async (_query) => ({
			bookmarks: [{ id: 100, url: 'https://docs.nextcloud.com', title: 'Nextcloud Docs', tags: ['docs'] }],
		})),
		createBookmark: vi.fn(async (url, _title, _tags) => ({ bookmarkId: 101, url })),
	}
}

function createMockFilesApi(overrides: Partial<FileApi> = {}): FileApi {
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
		whiteboard: createMockWhiteboardApi(),
		flow: createMockFlowApi(),
		windmill: createMockWindmillApi(),
		n8n: createMockN8nApi(),
		agentfile: createMockAgentfileApi(),
		notes: createMockNotesApi(),
		bookmarks: createMockBookmarksApi(),
		...overrides,
	}
}

describe('Workflow, Agentfile, Notes, Bookmarks capabilities and governance', () => {
	it('registers Whiteboard capabilities with reversal held', () => {
		expect(WHITEBOARD_CAPABILITIES).toHaveLength(2)
		expect(fileCapability('nc_whiteboard_read')).toMatchObject({
			subsystem: 'whiteboard',
			risk: 'read',
			individualApproval: false,
			reversal: 'not-applicable',
		})
		expect(fileCapability('nc_whiteboard_create')).toMatchObject({
			subsystem: 'whiteboard',
			risk: 'destructive',
			individualApproval: true,
			reversal: 'not-integrated',
		})
	})

	it('registers Flow capabilities with reversal held', () => {
		expect(FLOW_CAPABILITIES).toHaveLength(3)
		expect(fileCapability('nc_flow_read')?.risk).toBe('read')
		expect(fileCapability('nc_flow_create_draft')?.reversal).toBe('not-integrated')
		expect(fileCapability('nc_flow_update_rule')?.reversal).toBe('not-integrated')
	})

	it('registers Windmill and n8n capabilities with execution delivery classification', () => {
		expect(WINDMILL_CAPABILITIES).toHaveLength(3)
		expect(fileCapability('nc_windmill_read')?.risk).toBe('read')
		expect(fileCapability('nc_windmill_draft')?.reversal).toBe('not-integrated')
		expect(fileCapability('nc_windmill_run')?.reversal).toBe('compensating-only')

		expect(N8N_CAPABILITIES).toHaveLength(3)
		expect(fileCapability('nc_n8n_read')?.risk).toBe('read')
		expect(fileCapability('nc_n8n_draft')?.reversal).toBe('not-integrated')
		expect(fileCapability('nc_n8n_execute')?.reversal).toBe('compensating-only')
	})

	it('registers Agentfile, Notes, and Bookmarks capabilities', () => {
		expect(AGENTFILE_CAPABILITIES).toHaveLength(1)
		expect(fileCapability('nc_agentfile_read')?.risk).toBe('read')

		expect(NOTES_CAPABILITIES).toHaveLength(2)
		expect(fileCapability('nc_notes_read')?.risk).toBe('read')
		expect(fileCapability('nc_notes_create')?.reversal).toBe('not-integrated')

		expect(BOOKMARKS_CAPABILITIES).toHaveLength(2)
		expect(fileCapability('nc_bookmarks_read')?.risk).toBe('read')
		expect(fileCapability('nc_bookmarks_create')?.reversal).toBe('not-integrated')
	})
})

describe('Workflow and App argument validation', () => {
	it('validates whiteboard create arguments', () => {
		expect(() => validateWhiteboardCreateArgs({ path: '', name: 'sketch.whiteboard' })).toThrow('A valid destination directory path is required.')
		expect(() => validateWhiteboardCreateArgs({ path: '/Drawings', name: 'sketch.png' })).toThrow('A valid name ending with .whiteboard is required.')
		expect(() => validateWhiteboardCreateArgs({ path: '/Drawings', name: '' })).toThrow('A valid name ending with .whiteboard is required.')

		const valid = validateWhiteboardCreateArgs({ path: ' /Drawings ', name: ' draft.whiteboard ' })
		expect(valid.path).toBe('/Drawings')
		expect(valid.name).toBe('draft.whiteboard')
	})

	it('validates flow create and update arguments', () => {
		expect(() => validateFlowCreateDraftArgs({ description: '', operation: 'tag' })).toThrow('Rule description must be between 1 and 200 characters.')
		expect(() => validateFlowCreateDraftArgs({ description: 'Valid desc', operation: '' })).toThrow('Rule operation identifier is required.')
		expect(validateFlowCreateDraftArgs({ description: 'Archive old files', operation: 'archive', config: { days: 30 } })).toEqual({
			description: 'Archive old files',
			operation: 'archive',
			config: { days: 30 },
		})

		expect(() => validateFlowUpdateRuleArgs({ ruleId: 0 })).toThrow('A positive safe integer ruleId is required.')
		expect(validateFlowUpdateRuleArgs({ ruleId: 5, active: true })).toEqual({ ruleId: 5, active: true, deleteRule: undefined })
	})

	it('validates windmill draft and run arguments', () => {
		expect(() => validateWindmillDraftArgs({ path: 'invalid/path', summary: 'Flow', value: {} })).toThrow('Valid Windmill resource path (e.g. u/user/flow) is required.')
		expect(() => validateWindmillDraftArgs({ path: 'u/alice/flow1', summary: '', value: {} })).toThrow('Summary must be between 1 and 300 characters.')
		expect(validateWindmillDraftArgs({ path: 'u/alice/flow1', summary: 'Summary text', value: { step: 1 } })).toEqual({
			path: 'u/alice/flow1',
			summary: 'Summary text',
			value: { step: 1 },
		})

		expect(() => validateWindmillRunArgs({ path: '' })).toThrow('A valid Windmill runnable path is required.')
		expect(validateWindmillRunArgs({ path: 'u/alice/flow1', args: { env: 'prod' } })).toEqual({
			path: 'u/alice/flow1',
			args: { env: 'prod' },
		})
	})

	it('validates n8n draft and execute arguments', () => {
		expect(() => validateN8nDraftArgs({ name: '', nodes: [] })).toThrow('Workflow name must be between 1 and 200 characters.')
		expect(() => validateN8nDraftArgs({ name: 'Sync Flow', nodes: 'not array' as never })).toThrow('Nodes array is required.')
		expect(validateN8nDraftArgs({ name: 'Sync Flow', nodes: [{ type: 'cron' }] })).toEqual({
			name: 'Sync Flow',
			nodes: [{ type: 'cron' }],
			connections: {},
		})

		expect(() => validateN8nExecuteArgs({ workflowId: '' })).toThrow('A valid n8n workflowId is required.')
		expect(validateN8nExecuteArgs({ workflowId: 'wf-123', data: { trigger: 'webhook' } })).toEqual({
			workflowId: 'wf-123',
			data: { trigger: 'webhook' },
		})
	})

	it('validates agentfile read arguments', () => {
		expect(() => validateAgentfileReadArgs({ pathOrContent: '' })).toThrow('Manifest path or JSON string content is required.')
		expect(validateAgentfileReadArgs({ pathOrContent: 'Agentfile.json' })).toEqual({ pathOrContent: 'Agentfile.json' })
	})

	it('validates notes and bookmarks create arguments', () => {
		expect(() => validateNotesCreateArgs({ title: '', content: 'hello' })).toThrow('Note title must be between 1 and 200 characters.')
		expect(validateNotesCreateArgs({ title: 'Meeting notes', content: 'Notes text', category: 'Work' })).toEqual({
			title: 'Meeting notes',
			content: 'Notes text',
			category: 'Work',
		})

		expect(() => validateBookmarksCreateArgs({ url: 'not-a-url' })).toThrow('A valid normalized HTTP/HTTPS URL is required.')
		expect(() => validateBookmarksCreateArgs({ url: 'ftp://ftp.example.com' })).toThrow('A valid normalized HTTP/HTTPS URL is required.')
		expect(validateBookmarksCreateArgs({ url: 'https://nextcloud.com', title: 'Nextcloud Hub', tags: ['cloud'] })).toEqual({
			url: 'https://nextcloud.com',
			title: 'Nextcloud Hub',
			tags: ['cloud'],
		})
	})
})

describe('Workflow tool execution and approval', () => {
	it('reads notes and bookmarks directly without approval', async () => {
		const files = createMockFilesApi()
		const mockHost = { files, recordResult: vi.fn() }

		const nTools = notesTools(mockHost)
		const bTools = bookmarksTools(mockHost)

		const notesReadTool = nTools.find(t => t.name === 'nc_notes_read')!
		const bookmarksReadTool = bTools.find(t => t.name === 'nc_bookmarks_read')!

		const nResult = await notesReadTool.invoke({ category: 'Dev' }, { callId: 'call-nr', signal: new AbortController().signal, sessionId: 's1', turnId: 't1', services: NO_TOOL_SERVICES })
		expect(nResult).toHaveProperty('notes')
		expect(files.notes!.readNotes).toHaveBeenCalledWith('Dev', expect.anything())

		const bResult = await bookmarksReadTool.invoke({ query: 'docs' }, { callId: 'call-br', signal: new AbortController().signal, sessionId: 's1', turnId: 't1', services: NO_TOOL_SERVICES })
		expect(bResult).toHaveProperty('bookmarks')
		expect(files.bookmarks!.searchBookmarks).toHaveBeenCalledWith('docs', expect.anything())
	})

	it('reads agentfile manifest directly without approval', async () => {
		const files = createMockFilesApi()
		const mockHost = { files, recordResult: vi.fn() }
		const tools = agentfileTool(mockHost)
		const tool = tools[0]

		const result = await tool.invoke({ pathOrContent: 'Agentfile.json' }, { callId: 'call-af', signal: new AbortController().signal, sessionId: 's1', turnId: 't1', services: NO_TOOL_SERVICES })
		expect(result).toHaveProperty('name', 'TestAgent')
		expect(files.agentfile!.readManifest).toHaveBeenCalledWith('Agentfile.json', expect.anything())
	})

	it('gates notes creation through approval and executes upon allow', async () => {
		const files = createMockFilesApi()
		let callIndex = 0
		const gateway = new FakeGateway({
			users: [{ email: 'alice', password: 'password', id: 'u1' }],
			agents: [{ slug: 'nc-tools', name: 'Nextcloud Tools', ceiling: 'auto' }],
			model: async () => (++callIndex === 1 ? {
				toolCalls: [
					{
						id: 'call-note-create',
						name: 'nc_notes_create',
						arguments: { title: 'Sprint Retrospective', content: 'Good progress made.', category: 'Dev' },
					},
				],
			} : { text: 'Note created' }),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'password')

		const sendPromise = agent.send('Save retrospective notes', false)

		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})
		expect(part!.request.toolName).toBe('nc_notes_create')

		await agent.approve(part!)
		await sendPromise

		expect(files.notes!.createNote).toHaveBeenCalledWith('Sprint Retrospective', 'Good progress made.', 'Dev', expect.anything())
		await agent.dispose()
	})

	it('gates n8n execution through approval with compensation-only delivery classification', async () => {
		const files = createMockFilesApi()
		let callIndex = 0
		const gateway = new FakeGateway({
			users: [{ email: 'alice', password: 'password', id: 'u1' }],
			agents: [{ slug: 'nc-tools', name: 'Nextcloud Tools', ceiling: 'auto' }],
			model: async () => (++callIndex === 1 ? {
				toolCalls: [
					{
						id: 'call-n8n-exec',
						name: 'nc_n8n_execute',
						arguments: { workflowId: 'wf-sync-1', data: { mode: 'manual' } },
					},
				],
			} : { text: 'Executed' }),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'password')

		const sendPromise = agent.send('Execute sync workflow', false)

		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})
		expect(part!.request.toolName).toBe('nc_n8n_execute')

		await agent.approve(part!)
		await sendPromise

		expect(files.n8n!.executeWorkflow).toHaveBeenCalledWith('wf-sync-1', { mode: 'manual' }, expect.anything())
		await agent.dispose()
	})
})
