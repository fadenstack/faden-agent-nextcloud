import { describe, expect, it, vi } from 'vitest'
import {
	mailTools,
	talkTools,
	validateMailReadArgs,
	validateMailDraftArgs,
	validateMailSendArgs,
	validateTalkReadArgs,
	validateTalkSendMessageArgs,
	MAIL_MUTATIONS,
	TALK_MUTATIONS,
} from '../../src/integrations/nextcloud/mailTalk.ts'
import {
	MAIL_CAPABILITIES,
	TALK_CAPABILITIES,
	fileCapability,
} from '../../src/platform/catalog.ts'
import type {
	FileApi,
	FileContext,
	Identity,
	MailApi,
	TalkApi,
	MailMessage,
	TalkMessage,
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
	mailEnabled: true,
	talkEnabled: true,
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

const mockMessage: MailMessage = {
	id: 101,
	accountId: 1,
	folderId: 10,
	subject: 'Project Kickoff',
	from: 'bob@faden.test',
	to: ['alice@faden.test'],
	body: 'Welcome to the new release sprint.',
	date: '2026-10-29T10:00:00Z',
	unread: true,
}

const mockTalkMessage: TalkMessage = {
	id: 501,
	token: 'room-123',
	actorId: 'bob',
	actorDisplayName: 'Bob Smith',
	message: 'Hello everyone!',
	timestamp: 1730000000,
}

function createMockMailApi(): MailApi {
	return {
		readMail: vi.fn(async (query, accountId) => ({
			messages: [mockMessage],
			total: 1,
		})),
		saveDraft: vi.fn(async (draft) => ({
			draftId: 202,
			revision: 'mail-rev-1',
		})),
		sendDraft: vi.fn(async (draftId, expectedRevision) => ({
			delivered: true,
			messageId: 'msg-303',
		})),
	}
}

function createMockTalkApi(): TalkApi {
	return {
		readContext: vi.fn(async (roomToken) => ({
			room: {
				token: roomToken,
				name: 'Project Room',
				type: 2,
				participants: ['alice', 'bob'],
			},
			history: [mockTalkMessage],
		})),
		sendMessage: vi.fn(async (roomToken, message, replyTo) => ({
			messageId: 502,
			timestamp: 1730000500,
		})),
	}
}

function createMockFilesApi(overrides: Partial<FileApi> = {}): FileApi {
	const mail = createMockMailApi()
	const talk = createMockTalkApi()

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
		mail,
		talk,
		...overrides,
	}
}

describe('Mail and Talk capabilities and governance', () => {
	it('registers Mail capabilities with appropriate reversibility', () => {
		expect(MAIL_CAPABILITIES).toHaveLength(3)
		expect(fileCapability('nc_mail_read')).toMatchObject({
			subsystem: 'mail',
			risk: 'read',
			individualApproval: false,
			reversal: 'not-applicable',
		})
		expect(fileCapability('nc_mail_save_draft')).toMatchObject({
			subsystem: 'mail',
			risk: 'destructive',
			individualApproval: true,
			reversal: 'not-integrated',
		})
		expect(fileCapability('nc_mail_send_draft')).toMatchObject({
			subsystem: 'mail',
			risk: 'destructive',
			individualApproval: true,
			reversal: 'compensating-only',
		})
	})

	it('registers Talk capabilities with appropriate reversibility', () => {
		expect(TALK_CAPABILITIES).toHaveLength(2)
		expect(fileCapability('nc_talk_read')).toMatchObject({
			subsystem: 'talk',
			risk: 'read',
			individualApproval: false,
			reversal: 'not-applicable',
		})
		expect(fileCapability('nc_talk_send_message')).toMatchObject({
			subsystem: 'talk',
			risk: 'destructive',
			individualApproval: true,
			reversal: 'compensating-only',
		})
	})

	it('tracks mutation sets for write blocked enforcement', () => {
		expect(MAIL_MUTATIONS.has('nc_mail_save_draft')).toBe(true)
		expect(MAIL_MUTATIONS.has('nc_mail_send_draft')).toBe(true)
		expect(MAIL_MUTATIONS.has('nc_mail_read' as never)).toBe(false)
		expect(TALK_MUTATIONS.has('nc_talk_send_message')).toBe(true)
		expect(TALK_MUTATIONS.has('nc_talk_read' as never)).toBe(false)
	})
})

describe('Mail argument validation', () => {
	it('validates read args', () => {
		expect(validateMailReadArgs({})).toEqual({ query: undefined, accountId: undefined })
		expect(validateMailReadArgs({ query: 'invoice', accountId: 'acc-1' })).toEqual({
			query: 'invoice',
			accountId: 'acc-1',
		})
		expect(() => validateMailReadArgs({ query: 'a'.repeat(201) })).toThrow('Search query must be at most 200 characters.')
		expect(() => validateMailReadArgs({ accountId: 'bad id with spaces' })).toThrow('Invalid accountId format.')
	})

	it('validates save draft args', () => {
		expect(() => validateMailDraftArgs({ accountRef: '', subject: 'Test', body: 'Hello', recipients: ['bob@faden.test'] })).toThrow('A valid accountRef is required.')
		expect(() => validateMailDraftArgs({ accountRef: 'acc1', subject: 'Test', body: 'Hello', recipients: [] })).toThrow('Provide 1 to 50 recipients.')
		expect(() => validateMailDraftArgs({ accountRef: 'acc1', subject: 'Test', body: 'Hello', recipients: ['invalid-email'] })).toThrow('Invalid recipient email address')
		expect(() => validateMailDraftArgs({ accountRef: 'acc1', subject: '', body: 'Hello', recipients: ['bob@faden.test'] })).toThrow('Subject must be between 1 and 255 characters.')

		const valid = validateMailDraftArgs({
			accountRef: ' acc1 ',
			subject: ' Sprint Planning ',
			body: 'Let us meet at 2pm',
			recipients: [' bob@faden.test '],
		})
		expect(valid.accountRef).toBe('acc1')
		expect(valid.recipients).toEqual(['bob@faden.test'])
		expect(valid.subject).toBe('Sprint Planning')
	})

	it('validates send draft args', () => {
		expect(() => validateMailSendArgs({ draftId: 0, expectedRevision: 'rev-1' })).toThrow('A positive safe integer draftId is required.')
		expect(() => validateMailSendArgs({ draftId: 1, expectedRevision: '' })).toThrow('An exact expectedRevision is required before sending.')
		expect(validateMailSendArgs({ draftId: 10, expectedRevision: 'rev-xyz' })).toEqual({
			draftId: 10,
			expectedRevision: 'rev-xyz',
		})
	})
})

describe('Talk argument validation', () => {
	it('validates talk read args', () => {
		expect(() => validateTalkReadArgs({})).toThrow('A valid roomToken (4-64 alphanumeric characters) is required.')
		expect(() => validateTalkReadArgs({ roomToken: 'a' })).toThrow('A valid roomToken (4-64 alphanumeric characters) is required.')
		expect(validateTalkReadArgs({ roomToken: ' room-123 ' })).toEqual({ roomToken: 'room-123' })
	})

	it('validates talk send message args', () => {
		expect(() => validateTalkSendMessageArgs({ roomToken: '', message: 'Hi' })).toThrow('A valid roomToken is required.')
		expect(() => validateTalkSendMessageArgs({ roomToken: 'room-123', message: '' })).toThrow('Message must be between 1 and 4000 characters.')
		expect(() => validateTalkSendMessageArgs({ roomToken: 'room-123', message: 'a'.repeat(4001) })).toThrow('Message must be between 1 and 4000 characters.')

		const valid = validateTalkSendMessageArgs({ roomToken: ' room-123 ', message: ' Standup started ', replyTo: 42 })
		expect(valid.roomToken).toBe('room-123')
		expect(valid.message).toBe('Standup started')
		expect(valid.replyTo).toBe(42)
	})
})

describe('Mail and Talk agent execution and approval', () => {
	it('reads mail messages directly without approval', async () => {
		const files = createMockFilesApi()
		const mockHost = {
			files,
			recordResult: vi.fn(),
		}
		const tools = mailTools(mockHost)
		const readTool = tools.find(t => t.name === 'nc_mail_read')!

		const result = await readTool.invoke({ query: 'urgent' }, { callId: 'call-m1', signal: new AbortController().signal, sessionId: 's1', turnId: 't1' })
		expect(result).toHaveProperty('messages')
		expect(files.mail!.readMail).toHaveBeenCalledWith('urgent', undefined, expect.anything())
	})

	it('reads talk room context directly without approval', async () => {
		const files = createMockFilesApi()
		const mockHost = {
			files,
			recordResult: vi.fn(),
		}
		const tools = talkTools(mockHost)
		const readTool = tools.find(t => t.name === 'nc_talk_read')!

		const result = await readTool.invoke({ roomToken: 'room-123' }, { callId: 'call-t1', signal: new AbortController().signal, sessionId: 's1', turnId: 't1' })
		expect(result).toHaveProperty('room')
		expect(result).toHaveProperty('history')
		expect(files.talk!.readContext).toHaveBeenCalledWith('room-123', expect.anything())
	})

	it('gates mail draft creation through approval and executes upon allow', async () => {
		const files = createMockFilesApi()
		let callIndex = 0
		const gateway = new FakeGateway({
			users: [{ email: 'alice', password: 'password', id: 'u1' }],
			agents: [{ slug: 'nc-tools', name: 'Nextcloud Tools', ceiling: 'auto' }],
			model: async () => (++callIndex === 1 ? {
				toolCalls: [
					{
						id: 'call-mail-draft',
						name: 'nc_mail_save_draft',
						arguments: { accountRef: 'acc1', recipients: ['bob@faden.test'], subject: 'Draft Topic', body: 'Draft content' },
					},
				],
			} : { text: 'Draft created' }),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'password')

		const sendPromise = agent.send('Create draft email to Bob', false)

		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})
		expect(part!.request.toolName).toBe('nc_mail_save_draft')

		await agent.approve(part!)
		await sendPromise

		expect(files.mail!.saveDraft).toHaveBeenCalledWith(
			expect.objectContaining({
				accountRef: 'acc1',
				recipients: ['bob@faden.test'],
				subject: 'Draft Topic',
				body: 'Draft content',
			}),
			expect.anything(),
		)
		await agent.dispose()
	})

	it('gates talk send message through approval and executes upon allow', async () => {
		const files = createMockFilesApi()
		let callIndex = 0
		const gateway = new FakeGateway({
			users: [{ email: 'alice', password: 'password', id: 'u1' }],
			agents: [{ slug: 'nc-tools', name: 'Nextcloud Tools', ceiling: 'auto' }],
			model: async () => (++callIndex === 1 ? {
				toolCalls: [
					{
						id: 'call-talk-send',
						name: 'nc_talk_send_message',
						arguments: { roomToken: 'room-123', message: 'Team update' },
					},
				],
			} : { text: 'Message posted' }),
		})

		const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
		await agent.signIn('alice', 'password')

		const sendPromise = agent.send('Post to project room', false)

		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})
		expect(part!.request.toolName).toBe('nc_talk_send_message')

		await agent.approve(part!)
		await sendPromise

		expect(files.talk!.sendMessage).toHaveBeenCalledWith('room-123', 'Team update', undefined, expect.anything())
		await agent.dispose()
	})
})
