import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import ICAL from 'ical.js'
import { NextcloudFiles } from '../../src/integrations/nextcloud/files.ts'
import { patchIcs, patchVcard, assertPrivateComponent, buildEventIcs, buildTaskIcs, buildContactVcard } from '../../src/integrations/nextcloud/caldav.ts'
import type { Identity } from '../../src/integrations/nextcloud/types.ts'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn(), request: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => path, generateRemoteUrl: () => 'https://cloud.test/remote.php/dav/' }))
const identity: Identity = { userId: 'alice', binding: 'native', enabled: true, serverUrl: 'https://faden.test', agent: 'nc', development: true }
const event = 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:one\r\nSUMMARY:Old\r\nDTSTART:20261029T090000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n'
const card = 'BEGIN:VCARD\r\nVERSION:3.0\r\nUID:one\r\nFN:Alice\r\nEND:VCARD\r\n'
const xml = (responses: string) => `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="urn:ietf:params:xml:ns:carddav">${responses}</d:multistatus>`
function response(href: string, raw = event, status = 'HTTP/1.1 200 OK', etag = '&quot;rev&quot;', format = 'c:calendar-data') {
 return `<d:response><d:href>${href}</d:href><d:propstat><d:prop><d:getetag>${etag}</d:getetag><${format}><![CDATA[${raw}]]></${format}></d:prop>${status ? `<d:status>${status}</d:status>` : ''}</d:propstat></d:response>`
}
function filesWith(data: string) {
 const files = new NextcloudFiles(identity)
 vi.spyOn(files, 'assertIdentity').mockResolvedValue()
 vi.mocked(axios.request).mockResolvedValue({ status: 207, headers: {}, data })
 return files
}

describe('independent maintained-parser/DAV review', () => {
 beforeEach(() => vi.resetAllMocks())
 it.each(['SUMMARY', 'DESCRIPTION', 'LOCATION'])('treats timestamp-shaped %s as literal text', key => {
  const raw = patchIcs(event, 'VEVENT', { [key]: '20261101T100000Z' })
  const root = new ICAL.Component(ICAL.parse(raw))
  expect(root.getFirstSubcomponent('vevent')!.getFirstPropertyValue(key.toLowerCase())).toBe('20261101T100000Z')
 })
 it('preserves grouped repeated contact properties and refuses an ambiguous edit', () => {
  const raw = card.replace('FN:Alice', 'FN:Alice\r\nitem1.EMAIL:first@example.test\r\nitem2.EMAIL:second@example.test')
  expect(() => patchVcard(raw, { EMAIL: 'new@example.test' })).toThrow(/repeated|ambiguous/i)
  const result = patchVcard(raw, { NOTE: 'Keep both' })
  const parsed = new ICAL.Component(ICAL.parse(result))
  expect(parsed.getAllProperties('email').map(p => [String(p.getParameter('group')).toLowerCase(), p.getFirstValue()])).toEqual([
   ['item1', 'first@example.test'], ['item2', 'second@example.test'],
  ])
 })
 it('refuses a mixed event/task resource in the private event editing slice', () => {
  const mixed = event.replace('END:VCALENDAR', 'BEGIN:VTODO\r\nUID:two\r\nATTENDEE:mailto:bob@example.test\r\nEND:VTODO\r\nEND:VCALENDAR')
  expect(() => assertPrivateComponent(mixed, 'VEVENT')).toThrow(/component|scheduling|private/i)
 })
 it.each(['event', 'task', 'contact'])('rejects controls when building a new %s', family => {
  const build = () => family === 'event' ? buildEventIcs({ uid: 'one', summary: 'Bad\u0000text', startUtc: '2026-10-29T09:00:00Z', endUtc: '2026-10-29T10:00:00Z' }) : family === 'task' ? buildTaskIcs({ uid: 'one', summary: 'Bad\u0000text', dueDate: '2026-10-30' }) : buildContactVcard({ uid: 'one', fn: 'Bad\u0000text' })
  expect(build).toThrow(/control/i)
 })
 it('counts UTF-8 response bytes before XML parsing or disclosure', async () => {
  const raw = event.replace('SUMMARY:Old', 'SUMMARY:' + 'ü'.repeat(300000))
  const data = xml(response('/remote.php/dav/calendars/alice/personal/one.ics', raw))
  expect(data.length).toBeLessThan(512 * 1024)
  await expect(filesWith(data).calendar.readEvents('personal')).rejects.toThrow(/maximum|size|limit/i)
 })
 it.each(['', 'HTTP/1.1 404 Some 200 text', 'HTTP/1.1 1200 Invalid'])('does not treat an invalid propstat as success: %s', status => {
  return expect(filesWith(xml(response('/remote.php/dav/calendars/alice/personal/one.ics', event, status))).calendar.readEvents('personal')).resolves.toHaveLength(0)
 })
 it.each(['W/"weak"', '"one", "two"', '*'])('rejects a non-strong single-resource ETag: %s', etag => {
  const files = filesWith('')
  vi.mocked(axios.get).mockResolvedValue({ status: 200, headers: { etag }, data: event })
  return expect(files.calendar.getEvent('personal', 'one.ics')).rejects.toThrow(/revision|etag/i)
 })
 it('refuses malformed XML rather than reporting an empty calendar', () => {
  return expect(filesWith('<d:multistatus xmlns:d="DAV:"><broken>').calendar.readEvents('personal')).rejects.toThrow(/invalid|malformed|XML/i)
 })
 it('does not decode elements in a forged DAV namespace', () => {
  const data = xml(response('/remote.php/dav/calendars/alice/personal/one.ics')).replace('xmlns:d="DAV:"', 'xmlns:d="urn:forged"')
  return expect(filesWith(data).calendar.readEvents('personal')).rejects.toThrow(/invalid|DAV|XML/i)
 })
 it('refuses a nested resource href whose filename cannot identify that same object', () => {
  return expect(filesWith(xml(response('/remote.php/dav/calendars/alice/personal/nested/one.ics'))).calendar.readEvents('personal')).rejects.toThrow(/href|collection|child/i)
 })
 it('refuses a decoded filename containing a path separator', () => {
  return expect(filesWith(xml(response('/remote.php/dav/calendars/alice/personal/nested%2Fone.ics'))).calendar.readEvents('personal')).rejects.toThrow(/href|identifier|path/i)
 })
 it('marks a truncated contact collection incomplete', async () => {
  const data = xml(Array.from({ length: 51 }, (_, i) => response(`/remote.php/dav/addressbooks/users/alice/contacts/${i}.vcf`, card.replace('UID:one', `UID:${i}`), 'HTTP/1.1 200 OK', '&quot;rev&quot;', 'a:address-data')).join(''))
  const result = await filesWith(data).contacts.readContact('contacts')
  expect(result.contacts).toHaveLength(50)
  expect(result.complete).toBe(false)
 })
 it('rejects malformed single-resource content even when the ETag is valid', () => {
  const files = filesWith('')
  vi.mocked(axios.get).mockResolvedValue({ status: 200, headers: { etag: '"rev"' }, data: 'not an iCalendar document' })
  return expect(files.calendar.getEvent('personal', 'one.ics')).rejects.toThrow(/malformed|invalid|component/i)
 })
 it('does not let a failed resourcetype propstat hide a valid event', async () => {
  const valid = response('/remote.php/dav/calendars/alice/personal/one.ics').replace('</d:response>', '<d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat></d:response>')
  expect(await filesWith(xml(valid)).calendar.readEvents('personal')).toHaveLength(1)
 })
 it('reports an over-limit calendar query as incomplete', async () => {
  const data = xml(Array.from({ length: 51 }, (_, i) => response(`/remote.php/dav/calendars/alice/personal/${i}.ics`)).join(''))
  const result = await filesWith(data).calendar.readEvents('personal')
  expect(result).toHaveLength(50)
  expect(result.complete).toBe(false)
 })
 it('retains separate start/end timezones and complete quoted property headers', async () => {
  const raw = event.replace('SUMMARY:Old', 'SUMMARY;ALTREP="https://example.test/a:b":Old').replace('DTSTART:20261029T090000Z', 'DTSTART;TZID=Europe/Berlin:20261029T090000\r\nDTEND;TZID=Europe/London:20261029T100000')
  const result = await filesWith(xml(response('/remote.php/dav/calendars/alice/personal/one.ics', raw))).calendar.readEvents('personal')
  expect(result[0]).toMatchObject({ startTimezone: 'Europe/Berlin', endTimezone: 'Europe/London' })
  expect(result[0].propertyParameters!.SUMMARY).toContain('https://example.test/a:b')
 })
 it('retains a healthy exact-revision single resource', async () => {
  const files = filesWith('')
  vi.mocked(axios.get).mockResolvedValue({ status: 200, headers: { etag: '"healthy"' }, data: event })
  expect(await files.calendar.getEvent('personal', 'one.ics')).toMatchObject({ etag: 'healthy', raw: event, values: { UID: 'one', SUMMARY: 'Old' } })
 })
 it('folds serialized multibyte text by UTF-8 octets', () => {
  const result = patchIcs(event, 'VEVENT', { DESCRIPTION: '漢'.repeat(150) })
  for (const line of result.split('\r\n')) expect(new TextEncoder().encode(line).byteLength).toBeLessThanOrEqual(75)
 })
 it('refuses multiline telephone URI values in vCard 4 instead of inserting EMAIL', () => {
  const raw = card.replace('VERSION:3.0', 'VERSION:4.0').replace('FN:Alice', 'FN:Alice\r\nTEL;VALUE=uri:tel:+49111')
  expect(() => patchVcard(raw, { TEL: 'tel:+49222\nEMAIL:outsider@example.test' })).toThrow(/line|control|type/i)
 })
 it('refuses a non-text SUMMARY value type before applying a private text patch', () => {
  const raw = event.replace('SUMMARY:Old', 'SUMMARY;VALUE=URI:https://example.test/')
  expect(() => patchIcs(raw, 'VEVENT', { SUMMARY: 'Text\nATTENDEE:mailto:outsider@example.test' })).toThrow(/type|text/i)
 })
})
