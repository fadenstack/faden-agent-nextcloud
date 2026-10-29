/**
 * Nextcloud Mail and Talk (Spreed) adapters and tools (NC11, NC12)
 *
 * Experimental tool definitions. Native transports, MIME/read-state semantics,
 * server-owned previews and one-delivery/audience enforcement remain unimplemented.
 */
import { defineTool, type ToolCallContext } from '@fadenstack/agents'
import type { FileApi, MailDraft } from './types.ts'
import { positiveInteger, stringArgument } from './adapterGuards.ts'

export const MAIL_MUTATIONS = new Set([
	'nc_mail_save_draft',
	'nc_mail_send_draft',
])

export const TALK_MUTATIONS = new Set([
	'nc_talk_send_message',
])

export interface MailTalkHost {
	files: FileApi
	recordResult(callId: string, value: unknown, context: ToolCallContext): void
	commitMailMutation?(name: string, args: Readonly<Record<string, unknown>>, call: ToolCallContext): Promise<unknown>
	commitTalkMutation?(name: string, args: Readonly<Record<string, unknown>>, call: ToolCallContext): Promise<unknown>
}

export function validateMailReadArgs(args: Readonly<Record<string, unknown>>): { query?: string; accountId?: string } {
	const query = args.query !== undefined ? stringArgument(args.query, 'query').trim() : undefined
	const accountId = args.accountId !== undefined ? stringArgument(args.accountId, 'accountId').trim() : undefined
	if (query !== undefined && query.length > 200) throw new Error('Search query must be at most 200 characters.')
	if (accountId !== undefined && !/^[0-9a-zA-Z_-]+$/.test(accountId)) throw new Error('Invalid accountId format.')
	return { query, accountId }
}

export function validateMailDraftArgs(args: Readonly<Record<string, unknown>>): MailDraft {
	const accountRef = stringArgument(args.accountRef, 'accountRef').trim()
	const subject = stringArgument(args.subject, 'subject').trim()
	const body = stringArgument(args.body, 'body')
	if (!accountRef) throw new Error('A valid accountRef is required.')
	if (!subject || subject.length > 255) throw new Error('Subject must be between 1 and 255 characters.')
	if (!body || body.length > 65536) throw new Error('Draft body must be between 1 and 65,536 characters.')

	const rawRecipients = args.recipients
	if (!Array.isArray(rawRecipients) || !rawRecipients.length || rawRecipients.length > 50) {
		throw new Error('Provide 1 to 50 recipients.')
	}
	const recipients = rawRecipients.map(r => {
		const email = stringArgument(r, 'recipient').trim()
		if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) throw new Error(`Invalid recipient email address: ${email}`)
		return email
	})

	if (args.attachments !== undefined && (!Array.isArray(args.attachments) || args.attachments.length > 20)) throw new Error('Provide at most 20 attachment references.')
	const attachments = Array.isArray(args.attachments)
		? args.attachments.map(a => {
			const reference = stringArgument(a, 'attachment').trim()
			if (!reference || reference.length > 256 || /[\x00-\x1f\x7f]/.test(reference)) throw new Error('A bounded attachment reference is required.')
			return reference
		})
		: undefined
	const draftId = args.draftId !== undefined ? positiveInteger(args.draftId, 'draftId') : undefined

	return { id: draftId, accountRef, subject, body, recipients, attachments }
}

export function validateMailSendArgs(args: Readonly<Record<string, unknown>>): { draftId: number; expectedRevision: string } {
	const draftId = positiveInteger(args.draftId, 'draftId')
	const expectedRevision = stringArgument(args.expectedRevision, 'expectedRevision').trim()
	if (!expectedRevision || expectedRevision.length > 128) throw new Error('An exact expectedRevision is required before sending.')
	return { draftId, expectedRevision }
}

export function validateTalkReadArgs(args: Readonly<Record<string, unknown>>): { roomToken: string } {
	const roomToken = stringArgument(args.roomToken, 'roomToken').trim()
	if (!roomToken || !/^[0-9a-zA-Z_-]{4,64}$/.test(roomToken)) throw new Error('A valid roomToken (4-64 alphanumeric characters) is required.')
	return { roomToken }
}

export function validateTalkSendMessageArgs(args: Readonly<Record<string, unknown>>): { roomToken: string; message: string; replyTo?: number } {
	const roomToken = stringArgument(args.roomToken, 'roomToken').trim()
	if (!roomToken || !/^[0-9a-zA-Z_-]{4,64}$/.test(roomToken)) throw new Error('A valid roomToken is required.')
	const message = stringArgument(args.message, 'message').trim()
	if (!message || message.length > 4000) throw new Error('Message must be between 1 and 4000 characters.')
	const replyTo = args.replyTo !== undefined ? positiveInteger(args.replyTo, 'replyTo') : undefined
	return { roomToken, message, replyTo }
}

export function mailTools(host: MailTalkHost) {
	return [
		defineTool({
			name: 'nc_mail_read',
			toolClass: 'read',
			description: 'Read authorized Nextcloud Mail accounts, mailboxes, threads and message contents without altering unread or read-flag markers.',
			parameters: {
				type: 'object',
				properties: {
					query: { type: 'string', maxLength: 200, description: 'Optional search filter.' },
					accountId: { type: 'string', description: 'Optional specific account ID.' },
				},
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const { query, accountId } = validateMailReadArgs(args)
				if (!host.files.mail) throw new Error('Nextcloud Mail service is unavailable.')
				const result = await host.files.mail.readMail(query, accountId, c.signal)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
		defineTool({
			name: 'nc_mail_save_draft',
			toolClass: 'destructive',
			description: 'Create or update an unposted Nextcloud Mail draft with recipients, subject, body and optional attachments after approval.',
			parameters: {
				type: 'object',
				properties: {
					accountRef: { type: 'string', description: 'Account ID or reference.' },
					draftId: { type: 'integer', minimum: 1, description: 'Optional existing draft ID to update.' },
					subject: { type: 'string', minLength: 1, maxLength: 255 },
					body: { type: 'string', minLength: 1, maxLength: 65536 },
					recipients: { type: 'array', items: { type: 'string' }, minItems: 1, maxItems: 50 },
					attachments: { type: 'array', items: { type: 'string' } },
				},
				required: ['accountRef', 'subject', 'body', 'recipients'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				validateMailDraftArgs(args)
				if (!host.commitMailMutation) throw new Error('The previewed mutation executor is unavailable.')
				const result = await host.commitMailMutation('nc_mail_save_draft', args, c)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
		defineTool({
			name: 'nc_mail_send_draft',
			toolClass: 'destructive',
			description: 'Dispatch an approved email draft with exact server payload preview, revision matching and one-delivery semantics. Never retried automatically.',
			parameters: {
				type: 'object',
				properties: {
					draftId: { type: 'integer', minimum: 1, description: 'The resolved server draft ID.' },
					expectedRevision: { type: 'string', minLength: 1, maxLength: 128, description: 'Server draft revision token.' },
				},
				required: ['draftId', 'expectedRevision'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				validateMailSendArgs(args)
				if (!host.commitMailMutation) throw new Error('The previewed mutation executor is unavailable.')
				const result = await host.commitMailMutation('nc_mail_send_draft', args, c)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
	]
}

export function talkTools(host: MailTalkHost) {
	return [
		defineTool({
			name: 'nc_talk_read',
			toolClass: 'read',
			description: 'Inspect Talk room participants, unread counts and recent conversation history context without changing read pointers.',
			parameters: {
				type: 'object',
				properties: {
					roomToken: { type: 'string', minLength: 4, maxLength: 64, description: 'Talk room token.' },
				},
				required: ['roomToken'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const { roomToken } = validateTalkReadArgs(args)
				if (!host.files.talk) throw new Error('Nextcloud Talk service is unavailable.')
				const result = await host.files.talk.readContext(roomToken, c.signal)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
		defineTool({
			name: 'nc_talk_send_message',
			toolClass: 'destructive',
			description: 'Send a message or reply to an authorized Nextcloud Talk room after individual approval. Verifies audience and role.',
			parameters: {
				type: 'object',
				properties: {
					roomToken: { type: 'string', minLength: 4, maxLength: 64, description: 'Target room token.' },
					message: { type: 'string', minLength: 1, maxLength: 4000, description: 'Message content.' },
					replyTo: { type: 'integer', minimum: 1, description: 'Optional parent message ID for threaded reply.' },
				},
				required: ['roomToken', 'message'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				validateTalkSendMessageArgs(args)
				if (!host.commitTalkMutation) throw new Error('The previewed mutation executor is unavailable.')
				const result = await host.commitTalkMutation('nc_talk_send_message', args, c)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
	]
}
