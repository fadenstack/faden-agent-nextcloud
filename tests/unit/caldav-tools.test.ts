import { describe, expect, it, vi } from 'vitest'
import {
	calendarTools,
	tasksTools,
	contactsTools,
	CALENDAR_MUTATIONS,
	TASKS_MUTATIONS,
	CONTACTS_MUTATIONS,
	validateCalendarCreateArgs,
	validateCalendarPatchArgs,
	validateCalendarDeleteArgs,
	validateTasksCreateArgs,
	validateTasksPatchArgs,
	validateTasksDeleteArgs,
	validateContactsCreateArgs,
	validateContactsPatchArgs,
	validateContactsDeleteArgs,
	unfold,
	fold,
	escapeCalendarText,
	unescapeCalendarText,
	parseDavProperties,
	patchIcs,
	patchVcard,
	buildEventIcs,
	buildTaskIcs,
	buildContactVcard,
} from '../../src/integrations/nextcloud/caldav.ts'
import {
	CALENDAR_CAPABILITIES,
	TASKS_CAPABILITIES,
	CONTACTS_CAPABILITIES,
	fileCapability,
	FILE_CAPABILITIES,
} from '../../src/platform/catalog.ts'
import type {
	FileApi,
	FileContext,
	Identity,
	CalendarApi,
	TasksApi,
	ContactsApi,
	DavCalendar,
	DavCalendarObject,
	DavAddressBook,
	DavContact,
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
	development: false,
	calendarEnabled: true,
	tasksEnabled: true,
	contactsEnabled: true,
}

const mockContext: FileContext = {
	id: 'ctx-1',
	parentId: 1,
	expires: 9999999999,
	file: {
		id: 10,
		name: 'Calendar',
		path: '/Calendar',
		etag: 'dir-etag-1',
		mime: 'httpd/unix-directory',
		size: 0,
		folder: true,
		permissions: 31,
		url: 'https://cloud.test/f/10',
	},
}

const sampleEventIcs = [
	'BEGIN:VCALENDAR',
	'VERSION:2.0',
	'PRODID:-//Fadenstack//Test//EN',
	'BEGIN:VEVENT',
	'UID:event-123',
	'DTSTAMP:20261028T100000Z',
	'DTSTART;TZID=Europe/Berlin:20261029T090000',
	'DTEND;TZID=Europe/Berlin:20261029T100000',
	'SUMMARY:Morning Sync',
	'DESCRIPTION:Discuss architecture and rollout.',
	'LOCATION:Room 404',
	'X-FADEN-LAB-KEEP:unchanged',
	'CLASS:PRIVATE',
	'TRANSP:OPAQUE',
	'END:VEVENT',
	'END:VCALENDAR',
].join('\r\n') + '\r\n'

const sampleTaskIcs = [
	'BEGIN:VCALENDAR',
	'VERSION:2.0',
	'PRODID:-//Fadenstack//Test//EN',
	'BEGIN:VTODO',
	'UID:task-456',
	'DTSTAMP:20261028T100000Z',
	'DUE;VALUE=DATE:20261030',
	'SUMMARY:Review PR',
	'DESCRIPTION:Check types and coverage.',
	'STATUS:NEEDS-ACTION',
	'PERCENT-COMPLETE:0',
	'RELATED-TO;RELTYPE=PARENT:parent-task-789',
	'X-CUSTOM-TODO:retain-me',
	'END:VTODO',
	'END:VCALENDAR',
].join('\r\n') + '\r\n'

const sampleVcard = [
	'BEGIN:VCARD',
	'VERSION:3.0',
	'UID:contact-789',
	'FN:Dr. Alex Example',
	'N:Example;Alex;;Dr.;',
	'EMAIL:alex@example.invalid',
	'TEL;TYPE=WORK:+49-555-0199',
	'NOTE:Keep native user note',
	'CATEGORIES:Research,Core',
	'PHOTO;ENCODING=b;TYPE=PNG:iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
	'X-FADEN-LAB-KEEP:unchanged',
	'END:VCARD',
].join('\r\n') + '\r\n'

function createMockCalendarApi(): CalendarApi {
	const cal: DavCalendar = {
		id: 'personal',
		title: 'Personal Calendar',
		href: '/remote.php/dav/calendars/alice/personal/',
		components: ['VEVENT', 'VTODO'],
		canWrite: true,
	}
	const eventObj: DavCalendarObject = {
		href: '/remote.php/dav/calendars/alice/personal/event-123.ics',
		filename: 'event-123.ics',
		etag: 'etag-event-1',
		summary: 'Morning Sync',
		description: 'Discuss architecture and rollout.',
		location: 'Room 404',
		uid: 'event-123',
	}

	return {
		listCalendars: vi.fn(async () => [cal]),
		readEvents: vi.fn(async () => [eventObj]),
		getEvent: vi.fn(async (_calId, _filename) => ({
			etag: 'etag-event-1',
			raw: sampleEventIcs,
			values: parseDavProperties(sampleEventIcs, 'VEVENT').values,
		})),
		createEvent: vi.fn(async (_calId, filename, _data) => ({
			etag: 'etag-event-new',
			href: `/remote.php/dav/calendars/alice/personal/${filename}`,
		})),
		updateEvent: vi.fn(async (_calId, filename, _data, _etag) => ({
			etag: 'etag-event-updated',
			href: `/remote.php/dav/calendars/alice/personal/${filename}`,
		})),
		deleteEvent: vi.fn(async () => {}),
	}
}

function createMockTasksApi(): TasksApi {
	const cal: DavCalendar = {
		id: 'personal',
		title: 'Personal Calendar',
		href: '/remote.php/dav/calendars/alice/personal/',
		components: ['VEVENT', 'VTODO'],
		canWrite: true,
	}
	const taskObj: DavCalendarObject = {
		href: '/remote.php/dav/calendars/alice/personal/task-456.ics',
		filename: 'task-456.ics',
		etag: 'etag-task-1',
		summary: 'Review PR',
		description: 'Check types and coverage.',
		dueDate: '20261030',
		status: 'NEEDS-ACTION',
		percentComplete: '0',
		uid: 'task-456',
	}

	return {
		listTaskLists: vi.fn(async () => [cal]),
		readTasks: vi.fn(async () => [taskObj]),
		getTask: vi.fn(async (_calId, _filename) => ({
			etag: 'etag-task-1',
			raw: sampleTaskIcs,
			values: parseDavProperties(sampleTaskIcs, 'VTODO').values,
		})),
		createTask: vi.fn(async (_calId, filename, _data) => ({
			etag: 'etag-task-new',
			href: `/remote.php/dav/calendars/alice/personal/${filename}`,
		})),
		updateTask: vi.fn(async (_calId, filename, _data, _etag) => ({
			etag: 'etag-task-updated',
			href: `/remote.php/dav/calendars/alice/personal/${filename}`,
		})),
		deleteTask: vi.fn(async () => {}),
	}
}

function createMockContactsApi(): ContactsApi {
	const book: DavAddressBook = {
		id: 'contacts',
		title: 'Contacts',
		href: '/remote.php/dav/addressbooks/users/alice/contacts/',
		canWrite: true,
	}
	const contact: DavContact = {
		href: '/remote.php/dav/addressbooks/users/alice/contacts/contact-789.vcf',
		filename: 'contact-789.vcf',
		book: 'Contacts',
		uid: 'contact-789',
		name: 'Dr. Alex Example',
		email: 'alex@example.invalid',
		etag: 'etag-contact-1',
	}

	return {
		listAddressBooks: vi.fn(async () => [book]),
		findContacts: vi.fn(async () => ({ contacts: [contact], complete: true })),
		readContact: vi.fn(async (_bookId, _filename) => ({
			etag: 'etag-contact-1',
			raw: sampleVcard,
			values: parseDavProperties(sampleVcard, 'VCARD').values,
		})),
		createContact: vi.fn(async (_bookId, filename, _data) => ({
			etag: 'etag-contact-new',
			href: `/remote.php/dav/addressbooks/users/alice/contacts/${filename}`,
		})),
		updateContact: vi.fn(async (_bookId, filename, _data, _etag) => ({
			etag: 'etag-contact-updated',
			href: `/remote.php/dav/addressbooks/users/alice/contacts/${filename}`,
		})),
		deleteContact: vi.fn(async () => {}),
	}
}

function createMockFileApi(overrides?: Partial<FileApi>): FileApi {
	return {
		identity: vi.fn(async () => hostIdentity),
		assertIdentity: vi.fn(async () => {}),
		select: vi.fn(async () => mockContext),
		read: vi.fn(async () => ({
			file: mockContext.file,
			text: 'sample content',
			partial: false,
			totalCharacters: 14,
			processedCharacters: 14,
		})),
		pdf: vi.fn(async () => new Uint8Array()),
		list: vi.fn(async () => ({ folder: mockContext.file, files: [], partial: false })),
		propose: vi.fn(async () => ({
			id: 'p-1',
			target: 'Lab/test.txt',
			operation: 'create',
			content: 'sample',
			before: null,
			contextId: 'ctx-1',
		})),
		approve: vi.fn(async () => {}),
		commit: vi.fn(async () => ({
			file: { ...mockContext.file, name: 'test.txt' },
			operation: 'create',
		})),
		calendar: createMockCalendarApi(),
		tasks: createMockTasksApi(),
		contacts: createMockContactsApi(),
		...overrides,
	}
}

function createGateway() {
	return new FakeGateway({
		users: [{ email: 'alice', password: 'password', id: 'u1' }],
		agents: [{ slug: 'nc-tools', name: 'Nextcloud Tools', ceiling: 'auto' }],
	})
}

describe('NC10: Calendar, Tasks, and Contacts Tools', () => {
	it('preserves base 9 FILE_CAPABILITIES while registering Calendar, Tasks, and Contacts tools with supported reversal', () => {
		expect(FILE_CAPABILITIES).toHaveLength(9)
		expect(CALENDAR_CAPABILITIES).toHaveLength(5)
		expect(TASKS_CAPABILITIES).toHaveLength(4)
		expect(CONTACTS_CAPABILITIES).toHaveLength(6)

		expect(CALENDAR_MUTATIONS).toEqual(new Set(['nc_calendar_create', 'nc_calendar_patch', 'nc_calendar_delete']))
		expect(TASKS_MUTATIONS).toEqual(new Set(['nc_tasks_create', 'nc_tasks_patch', 'nc_tasks_delete']))
		expect(CONTACTS_MUTATIONS).toEqual(new Set(['nc_contacts_create', 'nc_contacts_patch', 'nc_contacts_delete']))

		// Read tools
		expect(fileCapability('nc_calendar_list')?.risk).toBe('read')
		expect(fileCapability('nc_calendar_list')?.reversal).toBe('not-applicable')
		expect(fileCapability('nc_calendar_read')?.risk).toBe('read')
		expect(fileCapability('nc_tasks_read')?.risk).toBe('read')
		expect(fileCapability('nc_contacts_list')?.risk).toBe('read')
		expect(fileCapability('nc_contacts_find')?.risk).toBe('read')
		expect(fileCapability('nc_contacts_read')?.risk).toBe('read')

		// Destructive mutation tools with supported reversal and individual approval
		for (const name of [...CALENDAR_MUTATIONS, ...TASKS_MUTATIONS, ...CONTACTS_MUTATIONS]) {
			const cap = fileCapability(name)
			expect(cap).toBeDefined()
			expect(cap?.risk).toBe('destructive')
			expect(cap?.individualApproval).toBe(true)
			expect(cap?.reversal).toBe('supported')
		}
	})

	describe('iCalendar / vCard parser, folding, unfolding and property preservation', () => {
		it('folds text at 74 UTF-8 bytes with CRLF + space, correctly handling multi-byte Unicode', () => {
			const longLine = 'SUMMARY:This is a very long line with multi-byte characters like München, Köln and Zürich to test folding.'
			const folded = fold(longLine)
			const lines = folded.split('\r\n')
			expect(lines.length).toBeGreaterThan(1)
			for (const line of lines) {
				const byteLen = new TextEncoder().encode(line).length
				expect(byteLen).toBeLessThanOrEqual(75)
			}
			expect(unfold(folded)).toBe(longLine)
		})

		it('escapes and unescapes calendar and vCard text safely', () => {
			const text = 'Line 1\nLine 2; with comma, and backslash \\'
			const escaped = escapeCalendarText(text)
			expect(escaped).toBe('Line 1\\nLine 2\\; with comma\\, and backslash \\\\')
			expect(unescapeCalendarText(escaped)).toBe(text)
		})

		it('patches iCalendar events preserving timezone parameters, alarms and custom properties', () => {
			const patched = patchIcs(sampleEventIcs, 'VEVENT', {
				SUMMARY: 'Updated Sync Title',
				LOCATION: 'Room 505',
			})
			expect(patched).toContain('SUMMARY:Updated Sync Title')
			expect(patched).toContain('LOCATION:Room 505')
			// Preserves original timezone and other properties
			expect(patched).toContain('DTSTART;TZID=Europe/Berlin:20261029T090000')
			expect(patched).toContain('X-FADEN-LAB-KEEP:unchanged')
			expect(patched).toContain('CLASS:PRIVATE')
			expect(patched).toContain('UID:event-123')
		})

		it('patches tasks preserving parent UID, completion status and unknown properties', () => {
			const patched = patchIcs(sampleTaskIcs, 'VTODO', {
				STATUS: 'COMPLETED',
				'PERCENT-COMPLETE': '100',
				SUMMARY: 'Completed PR Review',
			})
			expect(patched).toContain('STATUS:COMPLETED')
			expect(patched).toContain('PERCENT-COMPLETE:100')
			expect(patched).toContain('SUMMARY:Completed PR Review')
			expect(patched).toContain('RELATED-TO;RELTYPE=PARENT:parent-task-789')
			expect(patched).toContain('X-CUSTOM-TODO:retain-me')
			expect(patched).toContain('UID:task-456')
		})

		it('patches vCard preserving binary photo, note, categories and custom properties', () => {
			const patched = patchVcard(sampleVcard, {
				NOTE: 'Updated note from agent',
				TEL: '+49-555-0999',
			})
			expect(patched).toContain('NOTE:Updated note from agent')
			expect(patched).toContain('TEL;TYPE=WORK:+49-555-0999')
			// Preserves photo, categories, UID, custom fields (with RFC line folding)
			expect(unfold(patched)).toContain('PHOTO;ENCODING=b;TYPE=PNG:iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
			expect(patched).toContain('CATEGORIES:Research,Core')
			expect(patched).toContain('X-FADEN-LAB-KEEP:unchanged')
			expect(patched).toContain('UID:contact-789')
		})
	})

	describe('Rejection of attendees and recurring items', () => {
		it('rejects attendee-bearing calendar events in patchIcs', () => {
			const attendeeIcs = sampleEventIcs.replace('END:VEVENT', 'ATTENDEE;CN=Bob:mailto:bob@example.invalid\r\nEND:VEVENT')
			expect(() => patchIcs(attendeeIcs, 'VEVENT', { SUMMARY: 'New Summary' })).toThrow(/Attendee notifications require a separate explicitly approved operation/)
		})

		it('rejects recurring calendar events and exceptions in patchIcs', () => {
			const recurringIcs = sampleEventIcs.replace('END:VEVENT', 'RRULE:FREQ=WEEKLY;COUNT=3\r\nEND:VEVENT')
			expect(() => patchIcs(recurringIcs, 'VEVENT', { SUMMARY: 'New Summary' })).toThrow(/Recurring resources and exceptions require a separate editor/)

			const exceptionIcs = sampleEventIcs.replace('END:VEVENT', 'RECURRENCE-ID;TZID=Europe/Berlin:20261025T100000\r\nEND:VEVENT')
			expect(() => patchIcs(exceptionIcs, 'VEVENT', { SUMMARY: 'New Summary' })).toThrow(/Recurring resources and exceptions require a separate editor/)
		})
	})

	describe('Argument validation for Calendar, Tasks, and Contacts', () => {
		it('validates calendar create arguments requiring explicit UTC timestamps ending in Z', () => {
			expect(() => validateCalendarCreateArgs({
				calendarId: 'personal',
				filename: 'event-1.ics',
				summary: 'Test',
				startUtc: '2026-10-30T10:00:00', // Missing 'Z'
				endUtc: '2026-10-30T11:00:00Z',
			})).toThrow(/Explicit UTC startUtc ending in Z is required/)

			expect(() => validateCalendarCreateArgs({
				calendarId: 'personal',
				filename: 'event-1.ics',
				summary: 'Test',
				startUtc: '2026-10-30T12:00:00Z',
				endUtc: '2026-10-30T11:00:00Z', // start >= end
			})).toThrow(/startUtc must be before endUtc/)

			expect(() => validateCalendarCreateArgs({
				calendarId: 'personal',
				filename: 'not-an-ics.txt',
				summary: 'Test',
				startUtc: '2026-10-30T10:00:00Z',
				endUtc: '2026-10-30T11:00:00Z',
			})).toThrow(/filename ending in \.ics/)
		})

		it('validates tasks create arguments requiring all-day YYYY-MM-DD due dates', () => {
			expect(() => validateTasksCreateArgs({
				calendarId: 'personal',
				filename: 'task-1.ics',
				summary: 'Task 1',
				dueDate: '30-10-2026', // invalid date format
			})).toThrow(/all-day YYYY-MM-DD dueDate/)

			expect(() => validateTasksCreateArgs({
				calendarId: 'personal',
				filename: 'task-1.ics',
				summary: 'Task 1',
				dueDate: '2026-10-30',
				priority: 15, // invalid priority
			})).toThrow(/between 0 and 9/)

			const valid = validateTasksCreateArgs({
				calendarId: 'personal',
				filename: 'task-1.ics',
				summary: 'Task 1',
				dueDate: '2026-10-30',
				priority: 2,
			})
			expect(valid.dueDate).toBe('2026-10-30')
		})

		it('validates contacts create and patch arguments', () => {
			expect(() => validateContactsCreateArgs({
				bookId: 'contacts',
				filename: 'contact-1.txt', // not .vcf
				fn: 'Alex Example',
			})).toThrow(/filename ending in \.vcf/)

			expect(() => validateContactsCreateArgs({
				bookId: 'contacts',
				filename: 'contact-1.vcf',
				fn: '   ', // empty fn
			})).toThrow(/full name \(fn\)/)

			expect(() => validateContactsPatchArgs({
				bookId: 'contacts',
				filename: 'contact-1.vcf',
				expectedEtag: 'etag-1',
				changes: { unsupportedField: 'value' },
			})).toThrow(/Unsupported contact field/)
		})
	})

	describe('App availability and default-off tool discovery', () => {
		it('registers calendar, tasks, and contacts tools only when enabled and APIs are present', async () => {
			const files = createMockFileApi()
			const gateway = createGateway()
			const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
			await agent.signIn('alice', 'password')
			await vi.waitFor(() => expect(gateway.manifests).toHaveLength(1))

			const manifest = gateway.manifests[0]!.manifest
			const toolNames = (manifest.tools as { name: string }[]).map(t => t.name)

			expect(toolNames).toContain('nc_calendar_list')
			expect(toolNames).toContain('nc_calendar_create')
			expect(toolNames).toContain('nc_tasks_create')
			expect(toolNames).toContain('nc_contacts_create')
			await agent.dispose()
		})

		it('keeps calendar, tasks, and contacts tools absent when flags are disabled', async () => {
			const files = createMockFileApi()
			const gateway = createGateway()
			const agent = new NextcloudAgent({
				...hostIdentity,
				calendarEnabled: false,
				tasksEnabled: false,
				contactsEnabled: false,
			}, files, gateway.fetch)
			await agent.signIn('alice', 'password')
			await vi.waitFor(() => expect(gateway.manifests).toHaveLength(1))

			const manifest = gateway.manifests[0]!.manifest
			const toolNames = (manifest.tools as { name: string }[]).map(t => t.name)

			expect(toolNames).not.toContain('nc_calendar_list')
			expect(toolNames).not.toContain('nc_calendar_create')
			expect(toolNames).not.toContain('nc_tasks_create')
			expect(toolNames).not.toContain('nc_contacts_create')
			await agent.dispose()
		})

		it('throws clean error when calendar API is unavailable', async () => {
			const filesWithoutCal = createMockFileApi({ calendar: undefined })
			const agent = new NextcloudAgent(hostIdentity, filesWithoutCal)
			await expect(agent.listCalendars()).rejects.toThrow(/Calendar app is unavailable/)
			await expect(agent.readCalendar('personal')).rejects.toThrow(/Calendar app is unavailable/)
		})

		it('throws clean error when tasks or contacts APIs are unavailable', async () => {
			const filesWithoutTasks = createMockFileApi({ tasks: undefined, contacts: undefined })
			const agent = new NextcloudAgent(hostIdentity, filesWithoutTasks)
			await expect(agent.readTasks('personal')).rejects.toThrow(/Tasks app is unavailable/)
			await expect(agent.listContacts()).rejects.toThrow(/Contacts app is unavailable/)
		})
	})

	describe('Approval gating, conditional execution and preflight conflict detection', () => {
		it('detects preflight ETag conflict when updating a modified calendar event', async () => {
			const files = createMockFileApi()
			const agent = new NextcloudAgent(hostIdentity, files)

			await expect(agent.planCalendarMutation('nc_calendar_patch', {
				calendarId: 'personal',
				filename: 'event-123.ics',
				expectedEtag: 'stale-etag-999',
				changes: { summary: 'New Title' },
			}, new AbortController().signal)).rejects.toThrow(/Calendar resource changed\. Read it again/)
		})

		it('detects preflight ETag conflict when updating a modified task', async () => {
			const files = createMockFileApi()
			const agent = new NextcloudAgent(hostIdentity, files)

			await expect(agent.planTasksMutation('nc_tasks_patch', {
				calendarId: 'personal',
				filename: 'task-456.ics',
				expectedEtag: 'stale-etag-888',
				changes: { summary: 'Updated Task' },
			}, new AbortController().signal)).rejects.toThrow(/Task resource changed\. Read it again/)
		})

		it('plans calendar event creation with before: null and structured after properties', async () => {
			const files = createMockFileApi()
			const agent = new NextcloudAgent(hostIdentity, files)

			const plan = await agent.planCalendarMutation('nc_calendar_create', {
				calendarId: 'personal',
				filename: 'copilot-new-event.ics',
				summary: 'Project Sync',
				description: 'Bi-weekly status',
				startUtc: '2026-10-29T14:00:00Z',
				endUtc: '2026-10-29T15:00:00Z',
			}, new AbortController().signal)

			expect(plan.target).toBe('Calendar personal, event copilot-new-event.ics')
			expect(plan.before).toBeNull()
			expect((plan.after as Record<string, string>).SUMMARY).toBe('Project Sync')
			expect(plan.rawAfter).toContain('CLASS:PRIVATE')
			expect(plan.rawAfter).toContain('X-FADENSTACK-AI-GENERATED:TRUE')
		})

		it('plans task completion patch with STATUS: COMPLETED and PERCENT-COMPLETE: 100', async () => {
			const files = createMockFileApi()
			const agent = new NextcloudAgent(hostIdentity, files)

			const plan = await agent.planTasksMutation('nc_tasks_patch', {
				calendarId: 'personal',
				filename: 'task-456.ics',
				expectedEtag: 'etag-task-1',
				changes: { completed: true },
			}, new AbortController().signal)

			expect(plan.before).toBeDefined()
			expect((plan.after as Record<string, string>).STATUS).toBe('COMPLETED')
			expect((plan.after as Record<string, string>)['PERCENT-COMPLETE']).toBe('100')
			expect(plan.rawAfter).toContain('STATUS:COMPLETED')
			expect(plan.rawAfter).toContain('PERCENT-COMPLETE:100')
		})

		it('executes calendar event creation with conditional write If-None-Match: *', async () => {
			const files = createMockFileApi()
			let round = 0
			const gateway = new FakeGateway({
				users: [{ email: 'alice', password: 'password', id: 'u1' }],
				agents: [{ slug: 'nc-tools', name: 'Nextcloud Tools', ceiling: 'auto' }],
				model: () => {
					round++
					if (round === 1) {
						return {
							toolCalls: [{
								id: 'call-cal-1',
								name: 'nc_calendar_create',
								arguments: {
									calendarId: 'personal',
									filename: 'copilot-evt.ics',
									summary: 'New Event',
									startUtc: '2026-10-29T10:00:00Z',
									endUtc: '2026-10-29T11:00:00Z',
								},
							}],
						}
					}
					return { text: 'Done' }
				},
			})

			const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
			await agent.signIn('alice', 'password')

			const sendPromise = agent.send('Create private calendar event')
			let part: ApprovalPart | undefined
			await vi.waitFor(() => {
				part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
				expect(part?.isPending).toBe(true)
			})

			expect(part!.request.toolName).toBe('nc_calendar_create')
			await agent.approve(part!)
			await sendPromise

			expect(files.calendar!.createEvent).toHaveBeenCalledWith(
				'personal',
				'copilot-evt.ics',
				expect.stringContaining('SUMMARY:New Event'),
				expect.anything(),
			)
			await agent.dispose()
		})

		it('executes contact update with conditional write If-Match: expectedEtag', async () => {
			const files = createMockFileApi()
			let round = 0
			const gateway = new FakeGateway({
				users: [{ email: 'alice', password: 'password', id: 'u1' }],
				agents: [{ slug: 'nc-tools', name: 'Nextcloud Tools', ceiling: 'auto' }],
				model: () => {
					round++
					if (round === 1) {
						return {
							toolCalls: [{
								id: 'call-contact-1',
								name: 'nc_contacts_patch',
								arguments: {
									bookId: 'contacts',
									filename: 'contact-789.vcf',
									expectedEtag: 'etag-contact-1',
									changes: { tel: '+49-555-9988' },
								},
							}],
						}
					}
					return { text: 'Done' }
				},
			})

			const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
			await agent.signIn('alice', 'password')

			const sendPromise = agent.send('Update contact phone number')
			let part: ApprovalPart | undefined
			await vi.waitFor(() => {
				part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
				expect(part?.isPending).toBe(true)
			})

			expect(part!.request.toolName).toBe('nc_contacts_patch')
			await agent.approve(part!)
			await sendPromise

			expect(files.contacts!.updateContact).toHaveBeenCalledWith(
				'contacts',
				'contact-789.vcf',
				expect.stringContaining('+49-555-9988'),
				'etag-contact-1',
				expect.anything(),
			)
			await agent.dispose()
		})

		it('blocks subsequent writes when an approval is refused', async () => {
			const files = createMockFileApi()
			let round = 0
			const gateway = new FakeGateway({
				users: [{ email: 'alice', password: 'password', id: 'u1' }],
				agents: [{ slug: 'nc-tools', name: 'Nextcloud Tools', ceiling: 'auto' }],
				model: () => {
					round++
					if (round === 1) {
						return {
							toolCalls: [{
								id: 'call-refuse-1',
								name: 'nc_calendar_create',
								arguments: {
									calendarId: 'personal',
									filename: 'copilot-refused.ics',
									summary: 'Private Meeting',
									startUtc: '2026-10-30T10:00:00Z',
									endUtc: '2026-10-30T11:00:00Z',
								},
							}],
						}
					}
					return { text: 'Done' }
				},
			})

			const agent = new NextcloudAgent(hostIdentity, files, gateway.fetch)
			await agent.signIn('alice', 'password')

			const sendPromise = agent.send('Create a private meeting tomorrow at 10')
			await vi.waitFor(() => {
				const parts = agent.chat.turns.flatMap(t => [...t.parts])
				const p = parts.find(part => part instanceof ApprovalPart)
				expect(p).toBeDefined()
			})

			const part = agent.chat.turns.flatMap(t => [...t.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart
			expect(part).toBeDefined()
			part.deny('User refused meeting creation.')

			await sendPromise
			expect(files.calendar!.createEvent).not.toHaveBeenCalled()

			// Following write is blocked
			await expect(agent.planCalendarMutation('nc_calendar_create', {
				calendarId: 'personal',
				filename: 'copilot-another.ics',
				summary: 'Another Event',
				startUtc: '2026-10-30T14:00:00Z',
				endUtc: '2026-10-30T15:00:00Z',
			}, new AbortController().signal)).rejects.toThrow(/A previous change did not complete/)
			await agent.dispose()
		})
	})
})
