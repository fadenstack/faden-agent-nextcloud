/**
 * Nextcloud Mail and Talk (Spreed) adapters and tools (NC11, NC12)
 *
 * Bounded native transports use server-owned previews and the current user's
 * native session. Providers remain behind administrator development gates.
 */
import { defineTool } from '@fadenstack/agents'
import type { ToolCallContext } from '@fadenstack/agents'
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
	readMail?(args: Readonly<Record<string, unknown>>, signal: AbortSignal): Promise<unknown>
	readTalk?(args: Readonly<Record<string, unknown>>, signal: AbortSignal): Promise<unknown>
	recordResult(callId: string, value: unknown, context: ToolCallContext): void
	commitMailMutation?(name: string, args: Readonly<Record<string, unknown>>, call: ToolCallContext): Promise<unknown>
	commitTalkMutation?(name: string, args: Readonly<Record<string, unknown>>, call: ToolCallContext): Promise<unknown>
}

export function validateMailReadArgs(args: Readonly<Record<string, unknown>>): { query?: string, accountId?: string, mailboxId?: number, messageId?: number } {
	const query = args.query !== undefined ? stringArgument(args.query, 'query').trim() : undefined
	const accountId = args.accountId !== undefined ? stringArgument(args.accountId, 'accountId').trim() : undefined
	if (query !== undefined && query.length > 200) {
		throw new Error('Search query must be at most 200 characters.')
	}
	if (accountId !== undefined && !/^[0-9a-zA-Z_-]+$/.test(accountId)) {
		throw new Error('Invalid accountId format.')
	}
	return { query, accountId, ...(args.mailboxId !== undefined ? { mailboxId: positiveInteger(args.mailboxId, 'mailboxId') } : {}), ...(args.messageId !== undefined ? { messageId: positiveInteger(args.messageId, 'messageId') } : {}) }
}

export function validateMailDraftArgs(args: Readonly<Record<string, unknown>>): MailDraft {
	const accountRef = stringArgument(args.accountRef, 'accountRef').trim()
	const subject = stringArgument(args.subject, 'subject').trim()
	const body = stringArgument(args.body, 'body')
	if (!accountRef) {
		throw new Error('A valid accountRef is required.')
	}
	if (!subject || subject.length > 255) {
		throw new Error('Subject must be between 1 and 255 characters.')
	}
	if (!body || body.length > 65536) {
		throw new Error('Draft body must be between 1 and 65,536 characters.')
	}

	const rawRecipients = args.recipients
	if (!Array.isArray(rawRecipients) || !rawRecipients.length || rawRecipients.length > 50) {
		throw new Error('Provide 1 to 50 recipients.')
	}
	const recipients = rawRecipients.map((r) => {
		const email = stringArgument(r, 'recipient').trim()
		if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
			throw new Error(`Invalid recipient email address: ${email}`)
		}
		return email
	})

	if (args.attachments !== undefined && (!Array.isArray(args.attachments) || args.attachments.length > 20)) {
		throw new Error('Provide at most 20 attachment references.')
	}
	const attachments = Array.isArray(args.attachments)
		? args.attachments.map((a) => {
				const reference = stringArgument(a, 'attachment').trim()
				if (!reference || reference.length > 256 || /[\x00-\x1f\x7f]/.test(reference)) {
					throw new Error('A bounded attachment reference is required.')
				}
				return reference
			})
		: undefined
	const draftId = args.draftId !== undefined ? positiveInteger(args.draftId, 'draftId') : undefined

	return { id: draftId, accountRef, subject, body, recipients, attachments }
}

export function validateMailSendArgs(args: Readonly<Record<string, unknown>>): { draftId: number, expectedRevision: string } {
	const draftId = positiveInteger(args.draftId, 'draftId')
	const expectedRevision = stringArgument(args.expectedRevision, 'expectedRevision').trim()
	if (!expectedRevision || expectedRevision.length > 128) {
		throw new Error('An exact expectedRevision is required before sending.')
	}
	return { draftId, expectedRevision }
}

export function validateTalkReadArgs(args: Readonly<Record<string, unknown>>): { roomToken: string } {
	const roomToken = stringArgument(args.roomToken, 'roomToken').trim()
	if (!roomToken || !/^[0-9a-zA-Z_-]{4,64}$/.test(roomToken)) {
		throw new Error('A valid roomToken (4-64 alphanumeric characters) is required.')
	}
	return { roomToken }
}

export function validateTalkSendMessageArgs(args: Readonly<Record<string, unknown>>): { roomToken: string, message: string, replyTo?: number } {
	const roomToken = stringArgument(args.roomToken, 'roomToken').trim()
	if (!roomToken || !/^[0-9a-zA-Z_-]{4,64}$/.test(roomToken)) {
		throw new Error('A valid roomToken is required.')
	}
	const message = stringArgument(args.message, 'message').trim()
	if (!message || message.length > 4000) {
		throw new Error('Message must be between 1 and 4000 characters.')
	}
	const replyTo = args.replyTo !== undefined ? positiveInteger(args.replyTo, 'replyTo') : undefined
	return { roomToken, message, replyTo }
}

export function mailTools(host: MailTalkHost) {
	return [
		defineTool({
			name: 'nc_mail_read',
			toolClass: 'read',
			description: 'Read scoped Mail accounts, mailboxes and the latest 20 messages in a selected mailbox, or bounded MIME content by messageId. Does not mark messages read. Search filters only this recent window.',
			parameters: {
				type: 'object',
				properties: {
					query: { type: 'string', maxLength: 200, description: 'Optional search filter.' },
					accountId: { type: 'string', description: 'Optional specific account ID.' },
					mailboxId: { type: 'integer', minimum: 1, description: 'Mailbox returned by a scoped native read. Defaults to Inbox.' },
					messageId: { type: 'integer', minimum: 1, description: 'Read the complete bounded message/draft and its revision/attachment references.' },
				},
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const { query, accountId } = validateMailReadArgs(args)
				if (host.readMail) {
					const result = await host.readMail(args, c.signal)
					host.recordResult(c.callId, result, c)
					return result
				}
				if (!host.files.mail) {
					throw new Error('Nextcloud Mail service is unavailable.')
				}
				const result = await host.files.mail.readMail(query, accountId, c.signal)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
		defineTool({
			name: 'nc_mail_save_draft',
			toolClass: 'destructive',
			description: 'Request native review to create or update an unsent Mail draft with recipients, subject, body and optional attachments. Invoke this tool to open the trusted Allow/Deny preview; no write occurs before Allow. Do not ask for typed chat approval or imitate the card. Saving never sends email.',
			parameters: {
				type: 'object',
				properties: {
					accountRef: { type: 'string', description: 'Account ID or reference.' },
					draftId: { type: 'integer', minimum: 1, description: 'Optional existing draft ID to update.' },
					expectedRevision: { type: 'string', description: 'For a persisted draft, use the exact content.revision returned by nc_mail_read with its messageId. This is the MIME revision, not resource.revision.' },
					subject: { type: 'string', minLength: 1, maxLength: 255 },
					body: { type: 'string', minLength: 1, maxLength: 65536 },
					recipients: { type: 'array', items: { type: 'string' }, minItems: 1, maxItems: 50 },
					cc: { type: 'array', items: { type: 'string' }, maxItems: 50 },
					bcc: { type: 'array', items: { type: 'string' }, maxItems: 50 },
					attachments: { type: 'array', items: { type: 'string' } },
				},
				required: ['accountRef', 'subject', 'body', 'recipients'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				validateMailDraftArgs(args)
				if (!host.commitMailMutation) {
					throw new Error('The previewed mutation executor is unavailable.')
				}
				const result = await host.commitMailMutation('nc_mail_save_draft', args, c)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
		defineTool({
			name: 'nc_mail_send_draft',
			openWorld: true,
			toolClass: 'destructive',
			description: 'Request native review to send a persisted email draft once, with exact payload preview and revision checks. Invoke this tool to open the trusted Allow/Deny preview; no send occurs before Allow. Do not ask for typed chat approval or imitate the card. Report SMTP acceptance separately from delivery. Never retry an uncertain send.',
			parameters: {
				type: 'object',
				properties: {
					draftId: { type: 'integer', minimum: 1, description: 'The resolved server draft ID.' },
					expectedRevision: { type: 'string', minLength: 1, maxLength: 128, description: 'Exact content.revision returned by nc_mail_read for this draft messageId. Use the MIME revision, not resource.revision; read the draft again if it changed.' },
				},
				required: ['draftId', 'expectedRevision'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				validateMailSendArgs(args)
				if (!host.commitMailMutation) {
					throw new Error('The previewed mutation executor is unavailable.')
				}
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
			description: 'Read current Talk room members and up to 50 recent messages without changing read pointers, notification markers or presence.',
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
				if (host.readTalk) {
					const result = await host.readTalk(args, c.signal)
					host.recordResult(c.callId, result, c)
					return result
				}
				if (!host.files.talk) {
					throw new Error('Nextcloud Talk service is unavailable.')
				}
				const result = await host.files.talk.readContext(roomToken, c.signal)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
		defineTool({
			name: 'nc_talk_send_message',
			openWorld: true,
			toolClass: 'destructive',
			description: 'Request native review to send a message or reply to an authorized Talk room, checking audience and role. Invoke this tool to open the trusted Allow/Deny preview; no send occurs before Allow. Do not ask for typed chat approval or imitate the card. Never retry an uncertain send.',
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
				if (!host.commitTalkMutation) {
					throw new Error('The previewed mutation executor is unavailable.')
				}
				const result = await host.commitTalkMutation('nc_talk_send_message', args, c)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
	]
}
