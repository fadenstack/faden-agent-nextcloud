import { NO_TOOL_SERVICES } from '@fadenstack/agents'
import { describe, expect, it, vi } from 'vitest'
import {
	collectivesTools,
	formsTools,
	validateCollectivesCreatePageArgs,
	validateCollectivesDeletePageArgs,
	validateFormsCreateFormArgs,
	validateFormsSubmitResponseArgs,
	COLLECTIVES_MUTATIONS,
	FORMS_MUTATIONS,
} from '../../src/integrations/nextcloud/collaborativeApps.ts'
import {
	COLLECTIVES_CAPABILITIES,
	FORMS_CAPABILITIES,
	fileCapability,
} from '../../src/platform/catalog.ts'
import type {
	FileApi,
	FileContext,
	Identity,
	CollectivesApi,
	FormsApi,
	Collective,
	CollectivePage,
	FormDefinition,
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
	collectivesEnabled: true,
	formsEnabled: true,
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

const mockCollective: Collective = {
	id: 1,
	name: 'Engineering Knowledge Base',
}

const mockPage: CollectivePage = {
	id: 10,
	collectiveId: 1,
	title: 'Architecture Decision Records',
	parentId: null,
}

const mockForm: FormDefinition = {
	id: 42,
	title: 'Agent Feedback Survey',
	questions: [
		{ id: 1, text: 'How useful was the agent assistance?', type: 'short', mandatory: true },
	],
}

function createMockCollectivesApi(): CollectivesApi {
	return {
		readHierarchy: vi.fn(async (_cid) => ({
			collectives: [mockCollective],
			pages: [mockPage],
		})),
		createPage: vi.fn(async (collectiveId, title, parentId) => ({
			pageId: 11,
			title,
		})),
		deletePage: vi.fn(async (pageId) => ({
			deleted: true,
			pageId,
		})),
	}
}

function createMockFormsApi(): FormsApi {
	return {
		readForm: vi.fn(async (_formId) => ({
			forms: [mockForm],
			submissions: [],
		})),
		createForm: vi.fn(async (title, _questions) => ({
			formId: 43,
			title,
		})),
		submitResponse: vi.fn(async (formId, _answers) => ({
			submissionId: 901,
			recorded: true,
		})),
	}
}

function createMockFilesApi(overrides: Partial<FileApi> = {}): FileApi {
	const collectives = createMockCollectivesApi()
	const forms = createMockFormsApi()

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
		collectives,
		forms,
		...overrides,
	}
}

describe('Collectives and Forms capabilities and governance', () => {
	it('registers Collectives capabilities with appropriate reversibility', () => {
		expect(COLLECTIVES_CAPABILITIES).toHaveLength(3)
		expect(fileCapability('nc_collectives_read')).toMatchObject({
			subsystem: 'collectives',
			risk: 'read',
			individualApproval: false,
			reversal: 'not-applicable',
		})
		expect(fileCapability('nc_collectives_create_page')).toMatchObject({
			subsystem: 'collectives',
			risk: 'destructive',
			individualApproval: true,
			reversal: 'not-integrated',
		})
		expect(fileCapability('nc_collectives_delete_page')).toMatchObject({
			subsystem: 'collectives',
			risk: 'destructive',
			individualApproval: true,
			reversal: 'not-integrated',
		})
	})

	it('registers Forms capabilities with appropriate reversibility', () => {
		expect(FORMS_CAPABILITIES).toHaveLength(3)
		expect(fileCapability('nc_forms_read')).toMatchObject({
			subsystem: 'forms',
			risk: 'read',
			individualApproval: false,
			reversal: 'not-applicable',
		})
		expect(fileCapability('nc_forms_create_form')).toMatchObject({
			subsystem: 'forms',
			risk: 'destructive',
			individualApproval: true,
			reversal: 'not-integrated',
		})
		expect(fileCapability('nc_forms_submit_response')).toMatchObject({
			subsystem: 'forms',
			risk: 'destructive',
			individualApproval: true,
			reversal: 'compensating-only',
		})
	})

	it('tracks mutation sets for write blocked enforcement', () => {
		expect(COLLECTIVES_MUTATIONS.has('nc_collectives_create_page')).toBe(true)
		expect(COLLECTIVES_MUTATIONS.has('nc_collectives_delete_page')).toBe(true)
		expect(COLLECTIVES_MUTATIONS.has('nc_collectives_read' as never)).toBe(false)
		expect(FORMS_MUTATIONS.has('nc_forms_create_form')).toBe(true)
		expect(FORMS_MUTATIONS.has('nc_forms_submit_response')).toBe(true)
		expect(FORMS_MUTATIONS.has('nc_forms_read' as never)).toBe(false)
	})
})

describe('Collectives and Forms argument validation', () => {
	it('validates collectives create page arguments', () => {
		expect(() => validateCollectivesCreatePageArgs({ collectiveId: 0, title: 'Docs' })).toThrow('A positive safe integer collectiveId is required.')
		expect(() => validateCollectivesCreatePageArgs({ collectiveId: 1, title: '' })).toThrow('Page title must be between 1 and 255 characters.')
		expect(() => validateCollectivesCreatePageArgs({ collectiveId: 1, title: 'a'.repeat(256) })).toThrow('Page title must be between 1 and 255 characters.')
		expect(() => validateCollectivesCreatePageArgs({ collectiveId: 1, title: 'Docs', parentId: -5 })).toThrow('A positive safe integer parentId is required.')

		const valid = validateCollectivesCreatePageArgs({ collectiveId: 2, title: ' Architecture ', parentId: 5 })
		expect(valid.collectiveId).toBe(2)
		expect(valid.title).toBe('Architecture')
		expect(valid.parentId).toBe(5)
	})

	it('validates collectives delete page arguments', () => {
		expect(() => validateCollectivesDeletePageArgs({ pageId: 0 })).toThrow('A positive safe integer pageId is required.')
		expect(validateCollectivesDeletePageArgs({ pageId: 15 })).toEqual({ pageId: 15 })
	})

	it('validates forms create form arguments', () => {
		expect(() => validateFormsCreateFormArgs({ title: '', questions: [{ text: 'Q1', type: 'short' }] })).toThrow('Form title must be between 1 and 255 characters.')
		expect(() => validateFormsCreateFormArgs({ title: 'Survey', questions: [] })).toThrow('Provide 1 to 50 questions.')
		expect(() => validateFormsCreateFormArgs({ title: 'Survey', questions: [{ text: '', type: 'short' }] })).toThrow('Question 1 text must be between 1 and 500 characters.')

		const valid = validateFormsCreateFormArgs({
			title: ' Developer Survey ',
			questions: [
				{ text: ' Your main language? ', type: 'short', mandatory: true },
				{ text: ' Frameworks used? ', type: 'checkbox', mandatory: false, options: ['Vue', 'React'] },
			],
		})
		expect(valid.title).toBe('Developer Survey')
		expect(valid.questions).toHaveLength(2)
		expect(valid.questions[0].text).toBe('Your main language?')
		expect(valid.questions[1].options).toEqual(['Vue', 'React'])
	})

	it('validates forms submit response arguments', () => {
		expect(() => validateFormsSubmitResponseArgs({ formId: 0, answers: { 1: 'Yes' } })).toThrow('A positive safe integer formId is required.')
		expect(() => validateFormsSubmitResponseArgs({ formId: 1, answers: 'invalid' as never })).toThrow('A structured answers object keyed by question ID is required.')
		expect(() => validateFormsSubmitResponseArgs({ formId: 1, answers: ['array'] as never })).toThrow('A structured answers object keyed by question ID is required.')

		const valid = validateFormsSubmitResponseArgs({ formId: 42, answers: { 1: 'Yes', 2: ['Option A'] } })
		expect(valid.formId).toBe(42)
		expect(valid.answers).toEqual({ 1: 'Yes', 2: ['Option A'] })
	})
})

describe('Collectives and Forms tool execution and approval', () => {
	it('reads collectives hierarchy directly without approval', async () => {
		const files = createMockFilesApi()
		const mockHost = {
			files,
			recordResult: vi.fn(),
		}
		const tools = collectivesTools(mockHost)
		const readTool = tools.find(t => t.name === 'nc_collectives_read')!

		const result = await readTool.invoke({ collectiveId: 1 }, { callId: 'call-c1', signal: new AbortController().signal, sessionId: 's1', turnId: 't1', services: NO_TOOL_SERVICES })
		expect(result).toHaveProperty('collectives')
		expect(result).toHaveProperty('pages')
		expect(files.collectives!.readHierarchy).toHaveBeenCalledWith(1, expect.anything())
	})

	it('reads forms directly without approval', async () => {
		const files = createMockFilesApi()
		const mockHost = {
			files,
			recordResult: vi.fn(),
		}
		const tools = formsTools(mockHost)
		const readTool = tools.find(t => t.name === 'nc_forms_read')!

		const result = await readTool.invoke({ formId: 42 }, { callId: 'call-f1', signal: new AbortController().signal, sessionId: 's1', turnId: 't1', services: NO_TOOL_SERVICES })
		expect(result).toHaveProperty('forms')
		expect(files.forms!.readForm).toHaveBeenCalledWith(42, expect.anything())
	})

	it('gates collective page creation through approval and executes upon allow', async () => {
		const files = createMockFilesApi()
		let callIndex = 0
		const gateway = new FakeGateway({
			users: [{ email: 'alice', password: 'password', id: 'u1' }],
			agents: [{ slug: 'nc-tools', name: 'Nextcloud Tools', ceiling: 'auto' }],
			model: async () => (++callIndex === 1 ? {
				toolCalls: [
					{
						id: 'call-col-create',
						name: 'nc_collectives_create_page',
						arguments: { collectiveId: 1, title: 'New Runbook' },
					},
				],
			} : { text: 'Page created' }),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'password')

		const sendPromise = agent.send('Create new runbook in collective', false)

		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})
		expect(part!.request.toolName).toBe('nc_collectives_create_page')

		await agent.approve(part!)
		await sendPromise

		expect(files.collectives!.createPage).toHaveBeenCalledWith(1, 'New Runbook', undefined, expect.anything())
		await agent.dispose()
	})

	it('gates form response submission through approval and executes upon allow', async () => {
		const files = createMockFilesApi()
		let callIndex = 0
		const gateway = new FakeGateway({
			users: [{ email: 'alice', password: 'password', id: 'u1' }],
			agents: [{ slug: 'nc-tools', name: 'Nextcloud Tools', ceiling: 'auto' }],
			model: async () => (++callIndex === 1 ? {
				toolCalls: [
					{
						id: 'call-form-submit',
						name: 'nc_forms_submit_response',
						arguments: { formId: 42, answers: { 1: 'Excellent' } },
					},
				],
			} : { text: 'Response submitted' }),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'password')

		const sendPromise = agent.send('Submit survey answers', false)

		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})
		expect(part!.request.toolName).toBe('nc_forms_submit_response')

		await agent.approve(part!)
		await sendPromise

		expect(files.forms!.submitResponse).toHaveBeenCalledWith(42, { 1: 'Excellent' }, expect.anything())
		await agent.dispose()
	})
})
