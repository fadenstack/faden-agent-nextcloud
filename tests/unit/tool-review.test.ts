import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { FakeGateway } from '@fadenstack/testing'
import { ApprovalPart } from '@fadenstack/ui'
import type { ToolCallContext } from '@fadenstack/agents'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { NextcloudFiles } from '../../src/integrations/nextcloud/files.ts'
import { patchIcs, patchVcard, parseDavProperties, validateTasksCreateArgs } from '../../src/integrations/nextcloud/caldav.ts'
import { validateUpdateRowArgs, sha256Hex } from '../../src/integrations/nextcloud/tables.ts'
import { validateDeckMoveArgs, cardContent } from '../../src/integrations/nextcloud/deck.ts'
import { EXTENDED_FILE_CAPABILITIES, CALENDAR_CAPABILITIES, TASKS_CAPABILITIES, CONTACTS_CAPABILITIES } from '../../src/platform/catalog.ts'
import type { Identity, FileApi } from '../../src/integrations/nextcloud/types.ts'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn(), request: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => path, generateRemoteUrl: () => 'https://cloud.test/remote.php/dav/' }))

const event = 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:one\r\nSUMMARY:Old\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n'
const identity: Identity = { userId: 'alice', binding: 'native', enabled: true, serverUrl: 'https://faden.test', agent: 'nc', development: false }

describe('independent NC08–NC10 review regressions', () => {
 beforeEach(() => vi.resetAllMocks())

 it('keeps newline text within its calendar property rather than adding an attendee', () => {
  const result = patchIcs(event, 'VEVENT', { SUMMARY: 'Agenda\r\nATTENDEE:mailto:outsider@example.test' })
  expect(parseDavProperties(result, 'VEVENT').values.ATTENDEE).toBeUndefined()
  expect(result).toContain('SUMMARY:Agenda\\nATTENDEE:mailto:outsider@example.test')
 })

 it('keeps newlines within a contact field rather than inserting another property', () => {
  const raw = 'BEGIN:VCARD\r\nVERSION:3.0\r\nUID:one\r\nFN:Alice\r\nNOTE:Old\r\nEND:VCARD\r\n'
  const result = patchVcard(raw, { NOTE: 'Note\nEMAIL:unapproved@example.test' })
  expect(parseDavProperties(result, 'VCARD').values.EMAIL).toBeUndefined()
 })

 it('detects folded attendee and recurrence-date properties before private event writes', () => {
  const attendee = event.replace('SUMMARY:Old', 'ATTE\r\n NDEE:mailto:bob@example.test\r\nSUMMARY:Old')
  expect(() => patchIcs(attendee, 'VEVENT', { SUMMARY: 'New' })).toThrow(/attendee/i)
  expect(() => patchIcs(event.replace('SUMMARY:Old', 'RDATE:20261101T100000Z\r\nSUMMARY:Old'), 'VEVENT', { SUMMARY: 'New' })).toThrow(/recurr/i)
 })

 it('refuses ambiguous repeated contact properties instead of replacing a different value from its preview', () => {
  const raw = 'BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Alice\r\nEMAIL;TYPE=HOME:first@example.test\r\nEMAIL;TYPE=WORK:second@example.test\r\nEND:VCARD\r\n'
  expect(() => patchVcard(raw, { EMAIL: 'new@example.test' })).toThrow(/ambiguous|multiple|repeated/i)
 })

 it('writes an all-day task date with VALUE=DATE instead of retaining a date-time timezone', () => {
  const raw = 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:one\r\nDUE;TZID=Europe/Berlin:20261030T090000\r\nEND:VTODO\r\nEND:VCALENDAR\r\n'
  const result = patchIcs(raw, 'VTODO', { DUE: '20261101' })
  expect(result).toContain('DUE;VALUE=DATE:20261101')
  expect(result).not.toContain('DUE;TZID')
 })

 it('rejects impossible due dates rather than producing invalid native tasks', () => {
  expect(() => validateTasksCreateArgs({ calendarId: 'personal', filename: 'task.ics', summary: 'Task', dueDate: '2026-02-30' })).toThrow(/date/i)
 })

 it.each([1.5, NaN, Infinity])('rejects non-integer native resource IDs: %s', id => {
  expect(() => validateUpdateRowArgs({ rowId: id, cells: { 1: 'x' }, expectedSchemaRevision: 'a'.repeat(64), expectedRowRevision: 'b'.repeat(64) })).toThrow(/integer/i)
  expect(() => validateDeckMoveArgs({ cardId: id, stackId: 2, order: 0, expectedRevision: 'a'.repeat(64) })).toThrow(/integer/i)
 })

 it('does not advertise integrated Undo for adapters without inverse handlers', () => {
  for (const capability of [...EXTENDED_FILE_CAPABILITIES, ...CALENDAR_CAPABILITIES, ...TASKS_CAPABILITIES, ...CONTACTS_CAPABILITIES]) {
   if (capability.risk !== 'read') expect(capability.reversal).toBe('not-integrated')
  }
 })

 it('does not publish experimental adapters in production merely because browser flags are set', async () => {
  const gateway = new FakeGateway({ agents: [{ slug: 'nc' }], users: [{ email: 'alice', password: 'test', agents: ['nc'] }] })
  const files = { assertIdentity: vi.fn(async () => {}), createFolder: vi.fn(), tables: {}, deck: {}, calendar: {}, tasks: {}, contacts: {} } as unknown as FileApi
  const host = new NextcloudAgent({ ...identity, extendedFilesEnabled: true, tablesEnabled: true, deckEnabled: true, calendarEnabled: true, tasksEnabled: true, contactsEnabled: true }, files, gateway.fetch)
  await host.signIn('alice', 'test')
  for (const name of ['nc_files_create_folder', 'nc_tables_read', 'nc_deck_read', 'nc_calendar_list', 'nc_tasks_read', 'nc_contacts_read']) expect(host.agent.find(name)).toBeUndefined()
  await host.dispose()
 })

 it('fails closed on a folder preflight error and does not dispatch MKCOL', async () => {
  const files = new NextcloudFiles(identity)
  vi.spyOn(files, 'assertIdentity').mockResolvedValue()
  vi.mocked(axios.request).mockRejectedValueOnce(new Error('Network interrupted'))
  await expect(files.createFolder('/Lab', 'New')).rejects.toThrow('Network interrupted')
  expect(axios.request).toHaveBeenCalledOnce()
 })

 it('does not swallow its own destination-exists conflict', async () => {
  const files = new NextcloudFiles(identity)
  vi.spyOn(files, 'assertIdentity').mockResolvedValue()
  vi.mocked(axios.request).mockResolvedValue({ status: 207, data: '', headers: {} })
  await expect(files.copyFile('/Lab/source.txt', 'copy.txt', 'revision')).rejects.toThrow(/exists/i)
  expect(axios.request).toHaveBeenCalledOnce()
 })

 it('encodes file path segments and keeps a renamed copy in the source folder', async () => {
  const files = new NextcloudFiles(identity)
  vi.spyOn(files, 'assertIdentity').mockResolvedValue()
  vi.mocked(axios.request).mockRejectedValueOnce({ response: { status: 404 } }).mockResolvedValueOnce({ status: 201, data: '', headers: {} })
  await files.copyFile('/Lab #1/source?.txt', 'copy.txt', 'revision')
  const request = vi.mocked(axios.request).mock.calls[1]![0]
  expect(request.url).toBe('https://cloud.test/remote.php/dav/files/alice/Lab%20%231/source%3F.txt')
  expect(request.headers).toMatchObject({ Destination: 'https://cloud.test/remote.php/dav/files/alice/Lab%20%231/copy.txt', 'If-Match': '"revision"', Overwrite: 'F' })
 })

 it.each(['..', '.', '../outside'])('refuses traversal in DAV collection IDs before transport: %s', calendarId => {
  const files = new NextcloudFiles(identity)
  vi.spyOn(files, 'assertIdentity').mockResolvedValue()
  return expect(files.calendar.getEvent(calendarId, 'one.ics')).rejects.toThrow(/identifier|path|calendar/i)
 })

 it('rejects wildcard ETags instead of changing any current revision', async () => {
  const files = new NextcloudFiles(identity)
  vi.spyOn(files, 'assertIdentity').mockResolvedValue()
  await expect(files.calendar.updateEvent('personal', 'one.ics', event, '*')).rejects.toThrow(/etag|revision/i)
  expect(axios.put).not.toHaveBeenCalled()
 })

 it('refuses stale sharing revisions before creating a share', async () => {
  const files = new NextcloudFiles(identity)
  vi.spyOn(files, 'assertIdentity').mockResolvedValue()
  vi.mocked(axios.request).mockResolvedValue({ status: 207, data: '<d:multistatus xmlns:d="DAV:"><d:response><d:propstat><d:prop><d:getetag>"new-revision"</d:getetag></d:prop></d:propstat></d:response></d:multistatus>', headers: {} })
  await expect(files.shareReadonly('/Lab/one.txt', 'bob', 'old-revision')).rejects.toThrow(/revision changed/i)
  expect(axios.post).not.toHaveBeenCalled()
 })

 it('rejects external address-book hrefs before disclosing a contact query or CSRF headers', async () => {
  const files = new NextcloudFiles(identity)
  vi.spyOn(files, 'assertIdentity').mockResolvedValue()
  vi.spyOn(files.contacts, 'listAddressBooks').mockResolvedValue([{ id: 'contacts', title: 'Contacts', href: 'https://outside.example.test/collect', canWrite: true }])
  await expect(files.contacts.findContacts('Alice')).rejects.toThrow(/address-book path/i)
  expect(axios.request).not.toHaveBeenCalled()
 })

 it('decodes a contact collection REPORT into bounded contact records', async () => {
  const files = new NextcloudFiles(identity)
  vi.spyOn(files, 'assertIdentity').mockResolvedValue()
  vi.mocked(axios.request).mockResolvedValue({ status: 207, headers: {}, data: '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav"><d:response><d:href>/remote.php/dav/addressbooks/users/alice/contacts/alice.vcf</d:href><d:propstat><d:prop><d:getetag>"rev"</d:getetag><c:address-data>BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Alice\r\nUID:one\r\nEND:VCARD\r\n</c:address-data></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>' })
  const result = await files.contacts.readContact('contacts')
  expect(result.contacts).toHaveLength(1)
  expect(result.contacts![0]).toMatchObject({ name: 'Alice', uid: 'one', filename: 'alice.vcf' })
  expect(result.raw).toBe('')
 })

 it('keeps the existing nine-tool production surface available', async () => {
  const gateway = new FakeGateway({ agents: [{ slug: 'nc' }], users: [{ email: 'alice', password: 'test', agents: ['nc'] }] })
  const files = { assertIdentity: vi.fn(async () => {}) } as unknown as FileApi
  const host = new NextcloudAgent(identity, files, gateway.fetch)
  await host.signIn('alice', 'test')
  expect(host.agent.find('nc_read_file')).toBeDefined()
  expect(host.agent.find('nc_update_text_file')).toBeDefined()
  await host.dispose()
 })
})

function expandedFixture(name: string, args: Record<string, unknown>) {
 let round = 0
 const gateway = new FakeGateway({ agents: [{ slug: 'nc', version: 1, contract: [{ name, risk: 'destructive' }] }], users: [{ email: 'alice', password: 'test', agents: ['nc'] }], model: () => ++round === 1 ? { toolCalls: [{ id: 'review-call', name, arguments: args }] } : { text: 'Finished' } })
 const write = vi.fn(async () => ({}))
 const files = {
  assertIdentity: vi.fn(async () => {}),
  createFolder: write,
  tables: { readTable: vi.fn(async () => ({ table: { id: 1, title: 'Lab' }, columns: [{ id: 1, title: 'Name', type: 'text', subtype: 'line' }], rows: [], schemaRevision: 'a'.repeat(64) })), addRow: write },
  deck: { readBoard: vi.fn(async () => ({ id: 1, board: { id: 1, title: 'Lab' }, stacks: [{ id: 1, title: 'Tasks', cards: [] }] })), createCard: write },
  calendar: { createEvent: write }, tasks: { createTask: write }, contacts: { createContact: write },
 } as unknown as FileApi
 const host = new NextcloudAgent({ ...identity, development: true, extendedFilesEnabled: true, tablesEnabled: true, deckEnabled: true, calendarEnabled: true, tasksEnabled: true, contactsEnabled: true }, files, gateway.fetch)
 return { host, files, gateway, write }
}

async function pending(host: NextcloudAgent): Promise<ApprovalPart> {
 let part!: ApprovalPart
 await vi.waitFor(() => { part = host.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart && p.isPending) as ApprovalPart; expect(part).toBeDefined() })
 return part
}

describe('exact expanded-action approval through the real pinned SDK', () => {
 const cases: { name: string; args: Record<string, unknown>; alter: Record<string, unknown> }[] = [
  { name: 'nc_files_create_folder', args: { name: 'Approved' }, alter: { name: 'Changed' } },
  { name: 'nc_tables_add_row', args: { tableId: 1, cells: { 1: 'Approved' }, expectedSchemaRevision: 'a'.repeat(64) }, alter: { cells: { 1: 'Changed' } } },
  { name: 'nc_deck_create', args: { boardId: 1, stackId: 1, title: 'Approved' }, alter: { title: 'Changed' } },
  { name: 'nc_calendar_create', args: { calendarId: 'personal', filename: 'event.ics', summary: 'Approved', startUtc: '2026-10-29T10:00:00Z', endUtc: '2026-10-29T11:00:00Z' }, alter: { summary: 'Changed' } },
  { name: 'nc_tasks_create', args: { calendarId: 'personal', filename: 'task.ics', summary: 'Approved', dueDate: '2026-10-30' }, alter: { summary: 'Changed' } },
  { name: 'nc_contacts_create', args: { bookId: 'contacts', filename: 'contact.vcf', fn: 'Approved' }, alter: { fn: 'Changed' } },
 ]
 it.each(cases)('refuses changed final arguments for $name after approval', async ({ name, args, alter }) => {
  const { host, write, gateway } = expandedFixture(name, args)
  await host.signIn('alice', 'test')
  const tool = host.agent.find(name)!
  const invoke = tool.invoke.bind(tool)
  tool.invoke = (finalArgs, call) => invoke({ ...finalArgs, ...alter }, call)
  const sending = host.send('Perform the selected operation', false)
  const part = await pending(host)
  await host.approve(part)
  await sending
  expect(write).not.toHaveBeenCalled()
  expect(JSON.stringify(gateway.modelCalls.flatMap(call => call.messages))).toContain('arguments changed')
  await host.dispose()
 })

 it.each(cases)('allows exactly one unchanged explicitly approved development action for $name', async ({ name, args }) => {
  const { host, write } = expandedFixture(name, args)
  await host.signIn('alice', 'test')
  const sending = host.send('Perform the selected operation', false)
  await host.approve(await pending(host))
  await sending
  expect(write).toHaveBeenCalledOnce()
  await host.dispose()
 })

 it('does not accept a merely prepared action before its approval is granted', async () => {
  const { host, write } = expandedFixture('nc_files_create_folder', { name: 'Approved' })
  await host.signIn('alice', 'test')
  const sending = host.send('Create the folder', false)
  const part = await pending(host)
  const call: ToolCallContext = { callId: part.request.callId, turnId: 'test', sessionId: 'test', signal: new AbortController().signal }
  await expect(host.commitMutation('nc_files_create_folder', { name: 'Approved' }, call)).rejects.toThrow(/individual approval/i)
  expect(write).not.toHaveBeenCalled()
  host.stop()
  await sending
  await host.dispose()
 })

 it('isolates the stored Deck payload from changes to the displayed preview after approval', async () => {
  const card = { id: 2, stackId: 1, title: 'Old', description: 'Keep', type: 'plain', order: 0, owner: { uid: 'alice' }, archived: false, done: null }
  const args = { boardId: 1, cardId: 2, expectedRevision: await sha256Hex(cardContent(card)), changes: { title: 'Approved' } }
  const { host, files, write } = expandedFixture('nc_deck_patch', args)
  vi.mocked(files.deck!.readBoard).mockResolvedValue({ id: 1, board: { id: 1, title: 'Lab' }, stacks: [{ id: 1, title: 'Tasks', cards: [card] }] })
  files.deck!.patchCard = write
  await host.signIn('alice', 'test')
  const tool = host.agent.find('nc_deck_patch')!
  const invoke = tool.invoke.bind(tool)
  let part!: ApprovalPart
  tool.invoke = (finalArgs, call) => { (part.request.arguments.content as Record<string, unknown>).title = 'Unapproved'; return invoke(finalArgs, call) }
  const sending = host.send('Update the card', false)
  part = await pending(host)
  await host.approve(part)
  await sending
  expect(write).toHaveBeenCalledWith(1, 1, 2, expect.objectContaining({ title: 'Approved', description: 'Keep' }), expect.any(AbortSignal))
  await host.dispose()
 })
})
