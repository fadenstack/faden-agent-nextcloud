import { describe, expect, it, vi } from 'vitest'
import { NextcloudFiles } from '../../src/integrations/nextcloud/files.ts'
import { mailTools, talkTools, validateMailDraftArgs, validateTalkSendMessageArgs } from '../../src/integrations/nextcloud/mailTalk.ts'
import { formsTools, collectivesTools, validateFormsCreateFormArgs } from '../../src/integrations/nextcloud/collaborativeApps.ts'
import { flowTools, whiteboardTools, windmillTools, n8nTools, notesTools, bookmarksTools, validateFlowUpdateRuleArgs, validateWhiteboardCreateArgs } from '../../src/integrations/nextcloud/workflowAgentfile.ts'
import { TOOL_ROLLOUT } from '../../src/platform/catalog.ts'
import type { FileApi, Identity } from '../../src/integrations/nextcloud/types.ts'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => `${location.origin}${path}`, generateRemoteUrl: (name: string) => `${location.origin}/remote.php/${name}/` }))
vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'native-test-token' }))
vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, text: string) => text }))

const identity: Identity = { userId: 'alice', binding: 'native-binding', enabled: true, serverUrl: 'https://faden.test', agent: 'review', development: true }
const call = () => ({ callId: 'review-call', sessionId: 'session', turnId: 'turn', signal: new AbortController().signal })

describe('independent full-suite runtime review', () => {
	it('does not mistake the research/prototype inventory for enabled production subsystems', () => {
		expect(TOOL_ROLLOUT.enabledSubsystems).toEqual(['files'])
	})

	it.each(['mail', 'talk', 'collectives', 'forms', 'whiteboard', 'flow', 'windmill', 'n8n', 'agentfile', 'notes', 'bookmarks'] as const)('records that %s has no production transport rather than counting its mock as native acceptance', provider => {
		const native = new NextcloudFiles(identity) as FileApi
		expect(native[provider]).toBeUndefined()
	})

	it.each(['false', 'true', 0, 1, null])('refuses a non-boolean Flow activation flag: %s', active => {
		expect(() => validateFlowUpdateRuleArgs({ ruleId: 3, active })).toThrow()
	})

	it('refuses competing Flow activate/delete intentions', () => {
		expect(() => validateFlowUpdateRuleArgs({ ruleId: 3, active: true, deleteRule: true })).toThrow()
		expect(() => validateFlowUpdateRuleArgs({ ruleId: 3 })).toThrow()
	})

	it.each(['../other.whiteboard', 'nested/other.whiteboard', '..\\other.whiteboard', 'bad\u0000.whiteboard'])('rejects a board filename that can escape one approved child: %s', name => {
		expect(() => validateWhiteboardCreateArgs({ path: '/Approved', name })).toThrow()
	})

	it('does not coerce model objects/numbers into action text', () => {
		expect(() => validateMailDraftArgs({ accountRef: {}, subject: 42, body: 'content', recipients: ['a@example.test'] })).toThrow()
		expect(() => validateTalkSendMessageArgs({ roomToken: 'room1234', message: { arbitrary: 'data' } })).toThrow()
	})

	it.each([null, 42, [], { text: 'Question', type: 'arbitrary-script' }, { text: 'Choice', type: 'multiple' }])('rejects malformed/unsupported native form questions: %j', question => {
		expect(() => validateFormsCreateFormArgs({ title: 'Form', questions: [question] })).toThrow()
	})

	it('requires the previewed mutation executor before any provider write, even for a standalone tool invocation', async () => {
		const writes = {
			mail: { saveDraft: vi.fn(), sendDraft: vi.fn() }, talk: { sendMessage: vi.fn() },
			collectives: { createPage: vi.fn(), deletePage: vi.fn() }, forms: { createForm: vi.fn(), submitResponse: vi.fn() },
			whiteboard: { createBoard: vi.fn() }, flow: { createDraftRule: vi.fn(), updateRule: vi.fn() },
			windmill: { createDraftFlow: vi.fn(), runFlow: vi.fn() }, n8n: { createDraftWorkflow: vi.fn(), executeWorkflow: vi.fn() },
			notes: { createNote: vi.fn() }, bookmarks: { createBookmark: vi.fn() },
		}
		const host = { files: writes as unknown as FileApi, recordResult: vi.fn() }
		const tools = [...mailTools(host), ...talkTools(host), ...collectivesTools(host), ...formsTools(host), ...whiteboardTools(host), ...flowTools(host), ...windmillTools(host), ...n8nTools(host), ...notesTools(host), ...bookmarksTools(host)]
		const args: Record<string, Record<string, unknown>> = {
			nc_mail_save_draft: { accountRef: '1', subject: 'Draft', body: 'Content', recipients: ['a@example.test'] },
			nc_mail_send_draft: { draftId: 1, expectedRevision: 'rev' }, nc_talk_send_message: { roomToken: 'room1234', message: 'Hello' },
			nc_collectives_create_page: { collectiveId: 1, title: 'Page' }, nc_collectives_delete_page: { pageId: 1 },
			nc_forms_create_form: { title: 'Form', questions: [{ text: 'Question', type: 'short' }] }, nc_forms_submit_response: { formId: 1, answers: { '1': 'answer' } },
			nc_whiteboard_create: { path: '/Approved', name: 'Board.whiteboard' }, nc_flow_create_draft: { description: 'Draft', operation: 'tag' }, nc_flow_update_rule: { ruleId: 1, active: false },
			nc_windmill_draft: { path: 'u/alice/flow', summary: 'Draft', value: {} }, nc_windmill_run: { path: 'u/alice/flow' },
			nc_n8n_draft: { name: 'Draft', nodes: [] }, nc_n8n_execute: { workflowId: 'workflow1' }, nc_notes_create: { title: 'Note', content: 'Content' }, nc_bookmarks_create: { url: 'https://example.test' },
		}
		for (const [name, input] of Object.entries(args)) {
			await expect(tools.find(tool => tool.name === name)!.invoke(input, call())).rejects.toThrow('previewed mutation executor')
		}
		for (const service of Object.values(writes)) for (const write of Object.values(service)) expect(write).not.toHaveBeenCalled()
		expect(host.recordResult).not.toHaveBeenCalled()
	})
})
