import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { generateRemoteUrl } from '@nextcloud/router'
import { NextcloudFiles } from '../../src/integrations/nextcloud/files.ts'
import {
	parseDavProperties,
	assertPrivateComponent,
	patchIcs,
	patchVcard,
	buildEventIcs,
	buildTaskIcs,
	buildContactVcard,
	unfold,
	fold,
	escapeCalendarText,
	unescapeCalendarText,
} from '../../src/integrations/nextcloud/caldav.ts'
import type { Identity } from '../../src/integrations/nextcloud/types.ts'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		put: vi.fn(),
		post: vi.fn(),
		delete: vi.fn(),
		request: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((path: string) => path),
	generateRemoteUrl: vi.fn(() => 'https://cloud.test/remote.php/dav/'),
}))

const identity: Identity = {
	userId: 'alice',
	binding: 'native',
	enabled: true,
	serverUrl: 'https://cloud.test',
	agent: 'nc-tools',
	development: true,
	calendarEnabled: true,
	tasksEnabled: true,
	contactsEnabled: true,
}

describe('NC10 maintained ical.js parser and hardened DAV read transport', () => {
	beforeEach(() => {
		vi.resetAllMocks()
		vi.mocked(generateRemoteUrl).mockReturnValue('https://cloud.test/remote.php/dav/')
	})

	describe('1. Maintained ical.js parser and serializer robustness', () => {
		it('handles literal escaped backslashes and semicolons in property text', () => {
			const ics = [
				'BEGIN:VCALENDAR',
				'VERSION:2.0',
				'PRODID:-//Fadenstack//Test//EN',
				'BEGIN:VEVENT',
				'UID:event-backslash-1',
				'DTSTAMP:20261029T080000Z',
				'DTSTART:20261029T090000Z',
				'DTEND:20261029T100000Z',
				'SUMMARY:File path test',
				'DESCRIPTION:Path is C:\\\\Work\\\\Project and value\\; with semicolon',
				'END:VEVENT',
				'END:VCALENDAR',
			].join('\r\n') + '\r\n'

			const parsed = parseDavProperties(ics, 'VEVENT')
			expect(parsed.values.DESCRIPTION).toContain('C:\\\\Work\\\\Project')
			expect(parsed.values.DESCRIPTION).toContain('value\\; with semicolon')

			const patched = patchIcs(ics, 'VEVENT', {
				LOCATION: 'D:\\Shared\\Folder',
			})
			expect(patched).toContain('UID:event-backslash-1')
			expect(patched).toContain(String.raw`LOCATION:D:\\Shared\\Folder`)
			expect(patched).toContain('DESCRIPTION:Path is C:\\\\Work\\\\Project and value\\; with semicolon')
		})

		it('parses multiline text with Unicode line folding and preserves accents and non-ASCII', () => {
			const longSummary = 'München and Zürich strategy review with Köln lab teams regarding Nextcloud integration'
			const raw = [
				'BEGIN:VCALENDAR',
				'VERSION:2.0',
				'PRODID:-//Test//EN',
				'BEGIN:VEVENT',
				'UID:event-unicode-fold',
				'DTSTAMP:20261029T080000Z',
				'DTSTART:20261029T090000Z',
				'DTEND:20261029T100000Z',
				'SUMMARY:München and Zürich strategy review with Köln lab',
				'  teams regarding Nextcloud integration',
				'END:VEVENT',
				'END:VCALENDAR',
			].join('\r\n') + '\r\n'

			const parsed = parseDavProperties(raw, 'VEVENT')
			expect(parsed.values.SUMMARY).toBe(longSummary)

			const patched = patchIcs(raw, 'VEVENT', {
				DESCRIPTION: 'Detailed note with German umlauts: Ä, Ö, Ü, ß and French é, à, ç.',
			})
			expect(patched).toContain('München and Zürich')
			// In RFC 5545, commas are escaped as \, in text values
			expect(unfold(patched)).toContain('Ä\\, Ö\\, Ü\\, ß and French')
			const repared = parseDavProperties(patched, 'VEVENT')
			expect(repared.values.DESCRIPTION).toContain('Ä\\, Ö\\, Ü\\, ß and French')
		})

		it('handles quoted parameters with colons and semicolons in property attributes', () => {
			const ics = [
				'BEGIN:VCALENDAR',
				'VERSION:2.0',
				'PRODID:-//Test//EN',
				'BEGIN:VEVENT',
				'UID:event-quoted-params',
				'DTSTAMP:20261029T080000Z',
				'DTSTART:20261029T090000Z',
				'DTEND:20261029T100000Z',
				'SUMMARY;ALTREP="http://cloud.test/item:1?view=full;format=ics":Quoted param meeting',
				'END:VEVENT',
				'END:VCALENDAR',
			].join('\r\n') + '\r\n'

			const parsed = parseDavProperties(ics, 'VEVENT')
			expect(parsed.values.SUMMARY).toBe('Quoted param meeting')

			const patched = patchIcs(ics, 'VEVENT', { LOCATION: 'Office A' })
			expect(patched).toContain('LOCATION:Office A')
			expect(patched).toContain('ALTREP="http://cloud.test/item:1?view=full;format=ics"')
		})

		it('handles lowercase and mixed-case property names in iCalendar components', () => {
			const lowercaseIcs = [
				'begin:vcalendar',
				'version:2.0',
				'prodid:-//Test//EN',
				'begin:vevent',
				'uid:lower-event-1',
				'dtstamp:20261029T080000Z',
				'dtstart:20261029T090000Z',
				'dtend:20261029T100000Z',
				'summary:Lowercase event title',
				'description:Lowercase description text',
				'end:vevent',
				'end:vcalendar',
			].join('\r\n') + '\r\n'

			const parsed = parseDavProperties(lowercaseIcs, 'VEVENT')
			expect(parsed.values.SUMMARY).toBe('Lowercase event title')
			expect(parsed.values.UID).toBe('lower-event-1')

			const patched = patchIcs(lowercaseIcs, 'VEVENT', {
				SUMMARY: 'Updated from Lowercase',
			})
			expect(patched).toContain('UID:lower-event-1')
			expect(patched).toContain('SUMMARY:Updated from Lowercase')
		})

		it('parses and patches vCard 3.0 and vCard 4.0 preserving photos, notes, and categories', () => {
			const vcard3 = [
				'BEGIN:VCARD',
				'VERSION:3.0',
				'UID:vcard-3-uid',
				'FN:Alice Johnson',
				'N:Johnson;Alice;;;',
				'EMAIL;TYPE=INTERNET:alice@example.test',
				'TEL;TYPE=CELL:+49-170-112233',
				'PHOTO;ENCODING=b;TYPE=PNG:iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
				'CATEGORIES:Research,Core',
				'NOTE:vCard 3.0 confidential note',
				'X-CUSTOM-LAB:LabAlpha',
				'END:VCARD',
			].join('\r\n') + '\r\n'

			const parsed3 = parseDavProperties(vcard3, 'VCARD')
			expect(parsed3.values.FN).toBe('Alice Johnson')
			expect(parsed3.values.NOTE).toBe('vCard 3.0 confidential note')
			expect(parsed3.values.CATEGORIES).toBe('Research,Core')

			const patched3 = patchVcard(vcard3, { NOTE: 'vCard 3.0 updated note' })
			expect(patched3).toContain('UID:vcard-3-uid')
			expect(patched3).toContain('NOTE:vCard 3.0 updated note')
			expect(patched3).toContain('CATEGORIES:Research,Core')
			expect(patched3).toContain('X-CUSTOM-LAB:LabAlpha')
			expect(unfold(patched3)).toContain('PHOTO;ENCODING=b;TYPE=PNG:iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')

			const vcard4 = [
				'BEGIN:VCARD',
				'VERSION:4.0',
				'UID:vcard-4-uid',
				'FN:Bob Williams',
				'N:Williams;Bob;;;',
				'EMAIL;TYPE=work:bob@example.test',
				'PHOTO:data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
				'CATEGORIES:Engineering',
				'NOTE:vCard 4.0 note',
				'item1.TEL:+1-555-4433',
				'item1.X-ABLabel:Work Mobile',
				'END:VCARD',
			].join('\r\n') + '\r\n'

			const parsed4 = parseDavProperties(vcard4, 'VCARD')
			expect(parsed4.values.FN).toBe('Bob Williams')
			expect(parsed4.values.NOTE).toBe('vCard 4.0 note')

			const patched4 = patchVcard(vcard4, { NOTE: 'vCard 4.0 updated note' })
			expect(patched4).toContain('UID:vcard-4-uid')
			expect(patched4).toContain('NOTE:vCard 4.0 updated note')
			expect(patched4).toContain('CATEGORIES:Engineering')
			expect(patched4).toContain('+1-555-4433')
			expect(patched4).toContain('Work Mobile')
		})

		it('preserves nested VALARM components inside VEVENT when patching', () => {
			const icsWithAlarm = [
				'BEGIN:VCALENDAR',
				'VERSION:2.0',
				'PRODID:-//Test//EN',
				'BEGIN:VEVENT',
				'UID:event-alarm-1',
				'DTSTAMP:20261029T080000Z',
				'DTSTART:20261029T090000Z',
				'DTEND:20261029T100000Z',
				'SUMMARY:Event with Alarm',
				'BEGIN:VALARM',
				'TRIGGER:-PT15M',
				'ACTION:DISPLAY',
				'DESCRIPTION:Reminder: Event in 15 minutes',
				'END:VALARM',
				'END:VEVENT',
				'END:VCALENDAR',
			].join('\r\n') + '\r\n'

			const patched = patchIcs(icsWithAlarm, 'VEVENT', { SUMMARY: 'Updated with preserved alarm' })
			expect(patched).toContain('SUMMARY:Updated with preserved alarm')
			expect(patched).toContain('BEGIN:VALARM')
			expect(patched).toContain('TRIGGER:-PT15M')
			expect(patched).toContain('ACTION:DISPLAY')
			expect(patched).toContain('DESCRIPTION:Reminder: Event in 15 minutes')
			expect(patched).toContain('END:VALARM')
		})

		it('preserves VTIMEZONE components in VCALENDAR when patching VEVENT', () => {
			const icsWithTz = [
				'BEGIN:VCALENDAR',
				'VERSION:2.0',
				'PRODID:-//Test//EN',
				'BEGIN:VTIMEZONE',
				'TZID:Europe/Berlin',
				'BEGIN:DAYLIGHT',
				'TZOFFSETFROM:+0100',
				'TZOFFSETTO:+0200',
				'TZNAME:CEST',
				'DTSTART:19700329T020000',
				'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
				'END:DAYLIGHT',
				'BEGIN:STANDARD',
				'TZOFFSETFROM:+0200',
				'TZOFFSETTO:+0100',
				'TZNAME:CET',
				'DTSTART:19701025T030000',
				'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
				'END:STANDARD',
				'END:VTIMEZONE',
				'BEGIN:VEVENT',
				'UID:event-tz-1',
				'DTSTAMP:20261029T080000Z',
				'DTSTART;TZID=Europe/Berlin:20261029T090000',
				'DTEND;TZID=Europe/Berlin:20261029T100000',
				'SUMMARY:Timezone preserved event',
				'END:VEVENT',
				'END:VCALENDAR',
			].join('\r\n') + '\r\n'

			const patched = patchIcs(icsWithTz, 'VEVENT', { SUMMARY: 'Patched timezone event' })
			expect(patched).toContain('SUMMARY:Patched timezone event')
			expect(patched).toContain('BEGIN:VTIMEZONE')
			expect(patched).toContain('TZID:Europe/Berlin')
			expect(patched).toContain('TZNAME:CEST')
			expect(patched).toContain('TZNAME:CET')
			expect(patched).toContain('END:VTIMEZONE')
		})

		it('strictly rejects unsupported versions like vCalendar 1.0 and vCard 2.1', () => {
			const vcal1 = 'BEGIN:VCALENDAR\r\nVERSION:1.0\r\nBEGIN:VEVENT\r\nUID:1\r\nSUMMARY:Old\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n'
			expect(() => assertPrivateComponent(vcal1, 'VEVENT')).toThrow(/Unsupported (?:vCalendar|iCalendar) version/i)
			expect(() => patchIcs(vcal1, 'VEVENT', { SUMMARY: 'New' })).toThrow(/Unsupported (?:vCalendar|iCalendar) version/i)

			const vcard2 = 'BEGIN:VCARD\r\nVERSION:2.1\r\nFN:Alice\r\nEND:VCARD\r\n'
			expect(() => assertPrivateComponent(vcard2, 'VCARD')).toThrow(/Unsupported vCard version/i)
			expect(() => patchVcard(vcard2, { FN: 'New Alice' })).toThrow(/Unsupported vCard version/i)
		})

		it('strictly rejects malformed nesting and truncated structures', () => {
			const unclosed = 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:1\r\nSUMMARY:Unclosed\r\n'
			expect(() => assertPrivateComponent(unclosed, 'VEVENT')).toThrow(/Malformed component nesting|Invalid ical/i)
			expect(() => patchIcs(unclosed, 'VEVENT', { SUMMARY: 'New' })).toThrow(/Malformed component nesting|Invalid ical/i)

			const garbage = 'NOT AN ICALENDAR OBJECT AT ALL'
			expect(() => assertPrivateComponent(garbage, 'VEVENT')).toThrow(/Malformed component nesting|Invalid ical/i)
		})

		it('strictly rejects attendee/scheduling properties in private event slice', () => {
			const withOrganizer = [
				'BEGIN:VCALENDAR',
				'VERSION:2.0',
				'BEGIN:VEVENT',
				'UID:one',
				'ORGANIZER;CN=Boss:mailto:boss@example.test',
				'SUMMARY:Private',
				'END:VEVENT',
				'END:VCALENDAR',
			].join('\r\n') + '\r\n'
			expect(() => patchIcs(withOrganizer, 'VEVENT', { SUMMARY: 'New' })).toThrow(/attendee/i)

			const withMethod = [
				'BEGIN:VCALENDAR',
				'VERSION:2.0',
				'METHOD:REQUEST',
				'BEGIN:VEVENT',
				'UID:one',
				'SUMMARY:Private',
				'END:VEVENT',
				'END:VCALENDAR',
			].join('\r\n') + '\r\n'
			expect(() => patchIcs(withMethod, 'VEVENT', { SUMMARY: 'New' })).toThrow(/attendee/i)
		})

		it('strictly rejects recurring properties and exceptions in private event slice', () => {
			const withRrule = [
				'BEGIN:VCALENDAR',
				'VERSION:2.0',
				'BEGIN:VEVENT',
				'UID:one',
				'RRULE:FREQ=DAILY;COUNT=5',
				'SUMMARY:Private',
				'END:VEVENT',
				'END:VCALENDAR',
			].join('\r\n') + '\r\n'
			expect(() => patchIcs(withRrule, 'VEVENT', { SUMMARY: 'New' })).toThrow(/recurr/i)

			const withExdate = [
				'BEGIN:VCALENDAR',
				'VERSION:2.0',
				'BEGIN:VEVENT',
				'UID:one',
				'EXDATE:20261101T100000Z',
				'SUMMARY:Private',
				'END:VEVENT',
				'END:VCALENDAR',
			].join('\r\n') + '\r\n'
			expect(() => patchIcs(withExdate, 'VEVENT', { SUMMARY: 'New' })).toThrow(/recurr/i)
		})

		it('refuses ambiguous repeated property modifications for multiple phone numbers or emails', () => {
			const multiPhone = [
				'BEGIN:VCARD',
				'VERSION:3.0',
				'FN:Charlie',
				'TEL;TYPE=HOME:+49-1111',
				'TEL;TYPE=WORK:+49-2222',
				'END:VCARD',
			].join('\r\n') + '\r\n'
			expect(() => patchVcard(multiPhone, { TEL: '+49-3333' })).toThrow(/ambiguous|repeated/i)

			const multiEmail = [
				'BEGIN:VCARD',
				'VERSION:4.0',
				'FN:Charlie',
				'EMAIL:charlie.home@example.test',
				'EMAIL:charlie.work@example.test',
				'END:VCARD',
			].join('\r\n') + '\r\n'
			expect(() => patchVcard(multiEmail, { EMAIL: 'charlie.new@example.test' })).toThrow(/ambiguous|repeated/i)
		})
	})

	describe('2. CalDAV/CardDAV read transport hardening in NextcloudFiles', () => {
		it('decodes only successful 200 OK propstats and ignores 404 or 403 propstats in REPORT', async () => {
			const files = new NextcloudFiles(identity)
			vi.spyOn(files, 'assertIdentity').mockResolvedValue()

			const multistatusWithMixedPropstats = [
				'<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">',
				'  <d:response>',
				'    <d:href>/remote.php/dav/calendars/alice/personal/event-1.ics</d:href>',
				'    <d:propstat>',
				'      <d:prop>',
				'        <d:getetag>"valid-etag-1"</d:getetag>',
				'        <cal:calendar-data><![CDATA[BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:ev-1\r\nSUMMARY:Valid Event\r\nDTSTART:20261029T090000Z\r\nDTEND:20261029T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n]]></cal:calendar-data>',
				'      </d:prop>',
				'      <d:status>HTTP/1.1 200 OK</d:status>',
				'    </d:propstat>',
				'    <d:propstat>',
				'      <d:prop>',
				'        <d:getetag>"error-etag-ignored"</d:getetag>',
				'        <cal:calendar-data><![CDATA[BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:ev-bad\r\nSUMMARY:Ignored\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n]]></cal:calendar-data>',
				'      </d:prop>',
				'      <d:status>HTTP/1.1 404 Not Found</d:status>',
				'    </d:propstat>',
				'  </d:response>',
				'</d:multistatus>',
			].join('\n')

			vi.mocked(axios.request).mockResolvedValueOnce({
				status: 207,
				headers: {},
				data: multistatusWithMixedPropstats,
			})

			const events = await files.calendar.readEvents('personal')
			expect(events).toHaveLength(1)
			expect(events[0].etag).toBe('valid-etag-1')
			expect(events[0].summary).toBe('Valid Event')
			expect(events[0].filename).toBe('event-1.ics')
		})

		it('decodes collection responses as collections and skips them when querying items', async () => {
			const files = new NextcloudFiles(identity)
			vi.spyOn(files, 'assertIdentity').mockResolvedValue()

			const multistatusWithCollection = [
				'<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">',
				'  <d:response>',
				'    <d:href>/remote.php/dav/calendars/alice/personal/</d:href>',
				'    <d:propstat>',
				'      <d:prop>',
				'        <d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>',
				'      </d:prop>',
				'      <d:status>HTTP/1.1 200 OK</d:status>',
				'    </d:propstat>',
				'  </d:response>',
				'  <d:response>',
				'    <d:href>/remote.php/dav/calendars/alice/personal/item.ics</d:href>',
				'    <d:propstat>',
				'      <d:prop>',
				'        <d:getetag>"item-etag"</d:getetag>',
				'        <cal:calendar-data><![CDATA[BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:item-1\r\nSUMMARY:Real Item\r\nDTSTART:20261029T090000Z\r\nDTEND:20261029T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n]]></cal:calendar-data>',
				'      </d:prop>',
				'      <d:status>HTTP/1.1 200 OK</d:status>',
				'    </d:propstat>',
				'  </d:response>',
				'</d:multistatus>',
			].join('\n')

			vi.mocked(axios.request).mockResolvedValueOnce({
				status: 207,
				headers: {},
				data: multistatusWithCollection,
			})

			const events = await files.calendar.readEvents('personal')
			expect(events).toHaveLength(1)
			expect(events[0].summary).toBe('Real Item')
		})

		it('validates hrefs for subpath installations', async () => {
			vi.mocked(generateRemoteUrl).mockReturnValue('https://cloud.test/my/subpath/remote.php/dav/')
			const files = new NextcloudFiles(identity)
			vi.spyOn(files, 'assertIdentity').mockResolvedValue()

			const subpathXml = [
				'<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">',
				'  <d:response>',
				'    <d:href>/my/subpath/remote.php/dav/calendars/alice/work/event-subpath.ics</d:href>',
				'    <d:propstat>',
				'      <d:prop>',
				'        <d:getetag>"etag-subpath"</d:getetag>',
				'        <cal:calendar-data><![CDATA[BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:sub-1\r\nSUMMARY:Subpath Event\r\nDTSTART:20261029T090000Z\r\nDTEND:20261029T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n]]></cal:calendar-data>',
				'      </d:prop>',
				'      <d:status>HTTP/1.1 200 OK</d:status>',
				'    </d:propstat>',
				'  </d:response>',
				'</d:multistatus>',
			].join('\n')

			vi.mocked(axios.request).mockResolvedValueOnce({
				status: 207,
				headers: {},
				data: subpathXml,
			})

			const events = await files.calendar.readEvents('work')
			expect(events).toHaveLength(1)
			expect(events[0].filename).toBe('event-subpath.ics')
			expect(events[0].href).toBe('/my/subpath/remote.php/dav/calendars/alice/work/event-subpath.ics')
		})

		it('rejects foreign origins in returned DAV hrefs', async () => {
			const files = new NextcloudFiles(identity)
			vi.spyOn(files, 'assertIdentity').mockResolvedValue()

			const foreignXml = [
				'<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">',
				'  <d:response>',
				'    <d:href>https://attacker.invalid/remote.php/dav/calendars/alice/personal/evil.ics</d:href>',
				'    <d:propstat>',
				'      <d:prop><d:getetag>"rev"</d:getetag></d:prop>',
				'      <d:status>HTTP/1.1 200 OK</d:status>',
				'    </d:propstat>',
				'  </d:response>',
				'</d:multistatus>',
			].join('\n')

			vi.mocked(axios.request).mockResolvedValueOnce({
				status: 207,
				headers: {},
				data: foreignXml,
			})

			await expect(files.calendar.readEvents('personal')).rejects.toThrow(/origin mismatch|DAV href outside/i)
		})

		it('rejects path traversal escaping the collection in returned DAV hrefs', async () => {
			const files = new NextcloudFiles(identity)
			vi.spyOn(files, 'assertIdentity').mockResolvedValue()

			const traversalXml = [
				'<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">',
				'  <d:response>',
				'    <d:href>/remote.php/dav/calendars/alice/personal/../../bob/private/leak.ics</d:href>',
				'    <d:propstat>',
				'      <d:prop><d:getetag>"rev"</d:getetag></d:prop>',
				'      <d:status>HTTP/1.1 200 OK</d:status>',
				'    </d:propstat>',
				'  </d:response>',
				'</d:multistatus>',
			].join('\n')

			vi.mocked(axios.request).mockResolvedValueOnce({
				status: 207,
				headers: {},
				data: traversalXml,
			})

			await expect(files.calendar.readEvents('personal')).rejects.toThrow(/DAV href outside/i)
		})

		it('rejects URL credential injection and query parameters in returned DAV hrefs', async () => {
			const files = new NextcloudFiles(identity)
			vi.spyOn(files, 'assertIdentity').mockResolvedValue()

			const credsXml = [
				'<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">',
				'  <d:response>',
				'    <d:href>https://user:pass@cloud.test/remote.php/dav/calendars/alice/personal/event.ics</d:href>',
				'    <d:propstat>',
				'      <d:prop><d:getetag>"rev"</d:getetag></d:prop>',
				'      <d:status>HTTP/1.1 200 OK</d:status>',
				'    </d:propstat>',
				'  </d:response>',
				'</d:multistatus>',
			].join('\n')

			vi.mocked(axios.request).mockResolvedValueOnce({
				status: 207,
				headers: {},
				data: credsXml,
			})

			await expect(files.calendar.readEvents('personal')).rejects.toThrow(/userinfo|credentials/i)

			const queryXml = [
				'<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">',
				'  <d:response>',
				'    <d:href>/remote.php/dav/calendars/alice/personal/event.ics?token=leak</d:href>',
				'    <d:propstat>',
				'      <d:prop><d:getetag>"rev"</d:getetag></d:prop>',
				'      <d:status>HTTP/1.1 200 OK</d:status>',
				'    </d:propstat>',
				'  </d:response>',
				'</d:multistatus>',
			].join('\n')

			vi.mocked(axios.request).mockResolvedValueOnce({
				status: 207,
				headers: {},
				data: queryXml,
			})

			await expect(files.calendar.readEvents('personal')).rejects.toThrow(/query or fragment/i)
		})

		it('enforces 512 KB response byte bounds on DAV responses', async () => {
			const files = new NextcloudFiles(identity)
			vi.spyOn(files, 'assertIdentity').mockResolvedValue()

			const hugeData = 'x'.repeat(513 * 1024)
			vi.mocked(axios.request).mockResolvedValueOnce({
				status: 207,
				headers: {},
				data: hugeData,
			})

			await expect(files.calendar.readEvents('personal')).rejects.toThrow(/exceeds (?:safe read limit|maximum allowed size)/i)

			vi.mocked(axios.get).mockResolvedValueOnce({
				status: 200,
				headers: { etag: '"etag-1"' },
				data: hugeData,
			})

			await expect(files.calendar.getEvent('personal', 'huge.ics')).rejects.toThrow(/exceeds (?:safe read limit|maximum allowed size)/i)
		})

		it('bounds collection records to max 50 objects and reports complete: false when truncated', async () => {
			const files = new NextcloudFiles(identity)
			vi.spyOn(files, 'assertIdentity').mockResolvedValue()

			const responses: string[] = []
			for (let i = 1; i <= 60; i++) {
				responses.push(`
					<d:response>
						<d:href>/remote.php/dav/calendars/alice/personal/event-${i}.ics</d:href>
						<d:propstat>
							<d:prop>
								<d:getetag>"etag-${i}"</d:getetag>
								<cal:calendar-data><![CDATA[BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:event-${i}\r\nSUMMARY:Event ${i}\r\nDTSTART:20261029T090000Z\r\nDTEND:20261029T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n]]></cal:calendar-data>
							</d:prop>
							<d:status>HTTP/1.1 200 OK</d:status>
						</d:propstat>
					</d:response>
				`)
			}

			const largeCollectionXml = `<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">${responses.join('')}</d:multistatus>`

			vi.mocked(axios.request).mockResolvedValueOnce({
				status: 207,
				headers: {},
				data: largeCollectionXml,
			})

			const events = await files.calendar.readEvents('personal')
			expect(events).toHaveLength(50)
		})

		it('strictly rejects missing or wildcard ETag revisions on single resource reads', async () => {
			const files = new NextcloudFiles(identity)
			vi.spyOn(files, 'assertIdentity').mockResolvedValue()

			vi.mocked(axios.get).mockResolvedValueOnce({
				status: 200,
				headers: {}, // No ETag header
				data: 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:1\r\nSUMMARY:Test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n',
			})
			await expect(files.calendar.getEvent('personal', 'no-etag.ics')).rejects.toThrow(/missing (?:or invalid ETag|revision metadata)/i)

			vi.mocked(axios.get).mockResolvedValueOnce({
				status: 200,
				headers: { etag: '*' }, // Wildcard ETag
				data: 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:1\r\nSUMMARY:Test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n',
			})
			await expect(files.calendar.getEvent('personal', 'wild-etag.ics')).rejects.toThrow(/missing (?:or invalid ETag|revision metadata)/i)

			vi.mocked(axios.get).mockResolvedValueOnce({
				status: 200,
				headers: { etag: '   ' }, // Whitespace ETag
				data: 'BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Alice\r\nEND:VCARD\r\n',
			})
			await expect(files.contacts.readContact('contacts', 'blank-etag.vcf')).rejects.toThrow(/missing (?:or invalid ETag|revision metadata)/i)
		})

		it('keeps contacts with identical display names distinct by preserving unique filenames and UIDs', async () => {
			const files = new NextcloudFiles(identity)
			vi.spyOn(files, 'assertIdentity').mockResolvedValue()

			const multiSameNameXml = [
				'<d:multistatus xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">',
				'  <d:response>',
				'    <d:href>/remote.php/dav/addressbooks/users/alice/contacts/alice-first.vcf</d:href>',
				'    <d:propstat>',
				'      <d:prop>',
				'        <d:getetag>"etag-alice-1"</d:getetag>',
				'        <card:address-data><![CDATA[BEGIN:VCARD\r\nVERSION:3.0\r\nUID:alice-uid-1\r\nFN:Alice Smith\r\nEMAIL:alice.smith.1@example.test\r\nEND:VCARD\r\n]]></card:address-data>',
				'      </d:prop>',
				'      <d:status>HTTP/1.1 200 OK</d:status>',
				'    </d:propstat>',
				'  </d:response>',
				'  <d:response>',
				'    <d:href>/remote.php/dav/addressbooks/users/alice/contacts/alice-second.vcf</d:href>',
				'    <d:propstat>',
				'      <d:prop>',
				'        <d:getetag>"etag-alice-2"</d:getetag>',
				'        <card:address-data><![CDATA[BEGIN:VCARD\r\nVERSION:3.0\r\nUID:alice-uid-2\r\nFN:Alice Smith\r\nEMAIL:alice.smith.2@example.test\r\nEND:VCARD\r\n]]></card:address-data>',
				'      </d:prop>',
				'      <d:status>HTTP/1.1 200 OK</d:status>',
				'    </d:propstat>',
				'  </d:response>',
				'</d:multistatus>',
			].join('\n')

			vi.spyOn(files.contacts, 'listAddressBooks').mockResolvedValueOnce([
				{
					id: 'contacts',
					title: 'Contacts',
					href: '/remote.php/dav/addressbooks/users/alice/contacts/',
					canWrite: true,
				},
			])

			vi.mocked(axios.request).mockResolvedValueOnce({
				status: 207,
				headers: {},
				data: multiSameNameXml,
			})

			const result = await files.contacts.findContacts('Alice')
			expect(result.contacts).toHaveLength(2)
			expect(result.contacts[0].name).toBe('Alice Smith')
			expect(result.contacts[0].filename).toBe('alice-first.vcf')
			expect(result.contacts[0].uid).toBe('alice-uid-1')
			expect(result.contacts[0].email).toBe('alice.smith.1@example.test')

			expect(result.contacts[1].name).toBe('Alice Smith')
			expect(result.contacts[1].filename).toBe('alice-second.vcf')
			expect(result.contacts[1].uid).toBe('alice-uid-2')
			expect(result.contacts[1].email).toBe('alice.smith.2@example.test')
		})

		it('accurately separates local timezone dates from UTC timestamps', async () => {
			const files = new NextcloudFiles(identity)
			vi.spyOn(files, 'assertIdentity').mockResolvedValue()

			const dateXml = [
				'<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">',
				'  <d:response>',
				'    <d:href>/remote.php/dav/calendars/alice/personal/tz-local.ics</d:href>',
				'    <d:propstat>',
				'      <d:prop>',
				'        <d:getetag>"etag-tz"</d:getetag>',
				'        <cal:calendar-data><![CDATA[BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:tz-local-uid\r\nSUMMARY:Local Timezone Event\r\nDTSTART;TZID=Europe/Berlin:20261029T093000\r\nDTEND;TZID=Europe/Berlin:20261029T103000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n]]></cal:calendar-data>',
				'      </d:prop>',
				'      <d:status>HTTP/1.1 200 OK</d:status>',
				'    </d:propstat>',
				'  </d:response>',
				'  <d:response>',
				'    <d:href>/remote.php/dav/calendars/alice/personal/utc.ics</d:href>',
				'    <d:propstat>',
				'      <d:prop>',
				'        <d:getetag>"etag-utc"</d:getetag>',
				'        <cal:calendar-data><![CDATA[BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:utc-uid\r\nSUMMARY:UTC Event\r\nDTSTART:20261029T080000Z\r\nDTEND:20261029T090000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n]]></cal:calendar-data>',
				'      </d:prop>',
				'      <d:status>HTTP/1.1 200 OK</d:status>',
				'    </d:propstat>',
				'  </d:response>',
				'</d:multistatus>',
			].join('\n')

			vi.mocked(axios.request).mockResolvedValueOnce({
				status: 207,
				headers: {},
				data: dateXml,
			})

			const events = await files.calendar.readEvents('personal')
			expect(events).toHaveLength(2)

			// Local timezone event: has startDate and timezone; startUtc is undefined (NOT mislabeled as UTC)
			const localEv = events.find(e => e.filename === 'tz-local.ics')!
			expect(localEv).toBeDefined()
			expect(localEv.timezone).toBe('Europe/Berlin')
			expect(localEv.startDate).toBe('2026-10-29T09:30:00')
			expect(localEv.endDate).toBe('2026-10-29T10:30:00')
			expect(localEv.startUtc).toBeUndefined()
			expect(localEv.endUtc).toBeUndefined()

			// UTC event: has startUtc and endUtc
			const utcEv = events.find(e => e.filename === 'utc.ics')!
			expect(utcEv).toBeDefined()
			expect(utcEv.startUtc).toBe('2026-10-29T08:00:00Z')
			expect(utcEv.endUtc).toBe('2026-10-29T09:00:00Z')
		})
	})
})
