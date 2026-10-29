import axios from '@nextcloud/axios'
import { getRequestToken } from '@nextcloud/auth'
import { generateUrl, generateRemoteUrl } from '@nextcloud/router'
import ICAL from 'ical.js'
import type { FileApi, Identity, FileContext, Listing, Proposal, ReadResult, WriteArguments, WriteResult, SearchTransport, SearchPlan, FileMetadata, SearchCapabilities, NativeContentResults, DavCreatePlan, HistoryApi, ShareEntry, FolderListing, FolderListingEntry, FolderCreateResult, FileCopyMoveResult, AppCapabilities, TablesApi, TableSnapshot, TableColumn, TableRow, DeckApi, DeckBoardSnapshot, DeckBoard, DeckStack, CalendarApi, TasksApi, ContactsApi, DavCalendar, DavCalendarObject, DavAddressBook, DavContact } from './types.ts'
import type { ResourcePurpose, NativeResource } from '../../platform/scope.ts'
import { sha256Hex } from './tables.ts'
import { davSegment, davFilePath, strongEtagHeader, positiveInteger } from './adapterGuards.ts'
import { parseDavProperties } from './caldav.ts'

const MAX_DAV_RESPONSE_BYTES = 512 * 1024

function resolveRouteTableId(): number | undefined {
	if (typeof location === 'undefined') return undefined
	const match = /^#\/table\/([1-9]\d*)\/?$/.exec(location.hash)
	return match ? Number(match[1]) : undefined
}

function resolveRouteBoardId(): number | undefined {
	if (typeof location === 'undefined') return undefined
	const match = /^#\/board\/([1-9]\d*)\/?$/.exec(location.hash)
	return match ? Number(match[1]) : undefined
}

const HISTORY_CONFLICTS: Record<string, string> = {
	inspection_inconclusive: 'The file content does not establish whether this attempt completed. Its outcome remains unresolved. Review the file and action history before making further changes.',
	revalidation_content_changed: 'The file content does not match its recorded state. Its revision was not verified. Review the file and action history before making further changes.',
	revalidation_not_needed: 'The recorded file revision is already current. Review the available Undo or Redo action.',
}

export class NextcloudFiles implements FileApi {

	constructor(readonly initial: Identity) {}
	#cloudIdentity: ((signal?: AbortSignal) => Promise<string>) | undefined
	setCloudIdentity(provider?: (signal?: AbortSignal) => Promise<string>): void { this.#cloudIdentity = provider }
	readonly history: HistoryApi = {
		availability: signal => this.authenticatedPost('/history/availability', {}, signal),
		list: signal => this.authenticatedPost('/history/list', {}, signal),
		prepare: (actionId, direction, signal) => this.authenticatedPost('/history/prepare', { actionId, direction }, signal),
		approve: async (preview, signal) => { await this.authenticatedPost(this.historyPath(preview.id, preview.digest, 'approve'), { digest: preview.digest }, signal) },
		execute: (preview, signal) => this.authenticatedPost(this.historyPath(preview.id, preview.digest, 'execute'), { digest: preview.digest }, signal),
		cancel: async (preview, signal) => { await this.authenticatedPost(this.historyPath(preview.id, preview.digest, 'cancel'), { digest: preview.digest }, signal) },
	}

	readonly tables: TablesApi = {
		readTable: async (tableId?: number, signal?: AbortSignal): Promise<TableSnapshot> => {
			await this.assertIdentity(signal)
			const id = positiveInteger(tableId ?? resolveRouteTableId(), 'tableId')
			if (!id) throw new Error('A valid tableId or open native Tables route is required.')
			const tableUrl = generateUrl(`/apps/tables/api/1/tables/${id}`)
			const colsUrl = generateUrl(`/apps/tables/api/1/tables/${id}/columns`)
			const rowsUrl = generateUrl(`/apps/tables/api/1/tables/${id}/rows?limit=101&offset=0`)
			const [tableRes, colsRes, rowsRes] = await Promise.all([
				axios.get<TableSnapshot['table']>(tableUrl, { headers: { 'OCS-APIRequest': 'true' }, signal, timeout: 15000 }),
				axios.get<TableColumn[]>(colsUrl, { headers: { 'OCS-APIRequest': 'true' }, signal, timeout: 15000 }),
				axios.get<TableRow[]>(rowsUrl, { headers: { 'OCS-APIRequest': 'true' }, signal, timeout: 15000 }),
			])
			const rows = rowsRes.data
			if (rows.length > 100) throw new Error('Prototype limit: open a table with at most 100 rows.')
			const columns = colsRes.data
			const schemaRevision = await sha256Hex(columns)
			return { table: tableRes.data, columns, rows, schemaRevision }
		},
		addColumn: async (tableId: number, title: string, type: 'text' | 'number', signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = generateUrl(`/apps/tables/api/1/tables/${tableId}/columns`)
			const res = await axios.post(url, { title, type, subtype: type === 'text' ? 'line' : '', mandatory: false }, {
				headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
				signal,
				timeout: 15000,
			})
			return res.data
		},
		addRow: async (tableId: number, cells: Record<string, unknown>, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = generateUrl(`/apps/tables/api/1/tables/${tableId}/rows`)
			const res = await axios.post(url, { data: cells }, {
				headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
				signal,
				timeout: 15000,
			})
			return res.data
		},
		updateRow: async (tableId: number, rowId: number, cells: Record<string, unknown>, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = generateUrl(`/apps/tables/api/1/rows/${rowId}`)
			const res = await axios.put(url, { data: cells }, {
				headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
				signal,
				timeout: 15000,
			})
			return res.data
		},
		deleteRow: async (tableId: number, rowId: number, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = generateUrl(`/apps/tables/api/1/rows/${rowId}`)
			const res = await axios.delete(url, {
				headers: { 'OCS-APIRequest': 'true' },
				signal,
				timeout: 15000,
			})
			return res.data
		},
	}

	readonly deck: DeckApi = {
		readBoard: async (boardId?: number, signal?: AbortSignal): Promise<DeckBoardSnapshot> => {
			await this.assertIdentity(signal)
			const id = positiveInteger(boardId ?? resolveRouteBoardId(), 'boardId')
			if (!id) throw new Error('A valid boardId or open native Deck board route is required.')
			const boardUrl = generateUrl(`/apps/deck/api/v1.0/boards/${id}`)
			const stacksUrl = generateUrl(`/apps/deck/api/v1.0/boards/${id}/stacks`)
			const [boardRes, stacksRes] = await Promise.all([
				axios.get<DeckBoard>(boardUrl, { headers: { 'OCS-APIRequest': 'true' }, signal, timeout: 15000 }),
				axios.get<DeckStack[]>(stacksUrl, { headers: { 'OCS-APIRequest': 'true' }, signal, timeout: 15000 }),
			])
			const totalCards = stacksRes.data.reduce((sum, s) => sum + (s.cards?.length ?? 0), 0)
			if (totalCards > 50) throw new Error('Limit exceeded: board has more than 50 cards.')
			return { id, board: boardRes.data, stacks: stacksRes.data }
		},
		patchCard: async (boardId: number, stackId: number, cardId: number, cardData: Record<string, unknown>, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = generateUrl(`/apps/deck/api/v1.0/boards/${boardId}/stacks/${stackId}/cards/${cardId}`)
			const res = await axios.put(url, cardData, {
				headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
				signal,
				timeout: 15000,
			})
			return res.data
		},
		createCard: async (boardId: number, stackId: number, title: string, description?: string, duedate?: string | null, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = generateUrl(`/apps/deck/api/v1.0/boards/${boardId}/stacks/${stackId}/cards`)
			const res = await axios.post(url, { title, description: description ?? '', type: 'plain', order: 999, duedate: duedate ?? null }, {
				headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
				signal,
				timeout: 15000,
			})
			return res.data
		},
		moveCard: async (cardId: number, stackId: number, order = 0, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = generateUrl(`/apps/deck/cards/${cardId}/reorder`)
			const res = await axios.put(url, { stackId, order }, {
				headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
				signal,
				timeout: 15000,
			})
			return res.data
		},
		assignCard: async (boardId: number, stackId: number, cardId: number, userId: string, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = generateUrl(`/apps/deck/api/v1.0/boards/${boardId}/stacks/${stackId}/cards/${cardId}/assignUser`)
			const res = await axios.put(url, { userId, type: 0 }, {
				headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
				signal,
				timeout: 15000,
			})
			return res.data
		},
		labelCard: async (boardId: number, stackId: number, cardId: number, labelId: number, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = generateUrl(`/apps/deck/api/v1.0/boards/${boardId}/stacks/${stackId}/cards/${cardId}/assignLabel`)
			const res = await axios.put(url, { labelId }, {
				headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
				signal,
				timeout: 15000,
			})
			return res.data
		},
	}

	private davBase(): string {
		return generateRemoteUrl('dav').replace(/\/?$/, '/')
	}

	private calendarHomeUrl(): URL {
		const baseHref = typeof location !== 'undefined' ? location.href : 'https://localhost/'
		return new URL(`${this.davBase()}calendars/${encodeURIComponent(this.initial.userId)}/`, baseHref)
	}

	private calendarCollectionUrl(calendarId: string): URL {
		const baseHref = typeof location !== 'undefined' ? location.href : 'https://localhost/'
		return new URL(`${this.davBase()}calendars/${encodeURIComponent(this.initial.userId)}/${davSegment(calendarId)}/`, baseHref)
	}

	private addressBookHomeUrl(): URL {
		const baseHref = typeof location !== 'undefined' ? location.href : 'https://localhost/'
		return new URL(`${this.davBase()}addressbooks/users/${encodeURIComponent(this.initial.userId)}/`, baseHref)
	}

	private addressBookCollectionUrl(bookId: string): URL {
		const baseHref = typeof location !== 'undefined' ? location.href : 'https://localhost/'
		return new URL(`${this.davBase()}addressbooks/users/${encodeURIComponent(this.initial.userId)}/${davSegment(bookId)}/`, baseHref)
	}

	readonly calendar: CalendarApi = {
		listCalendars: async (signal?: AbortSignal): Promise<DavCalendar[]> => {
			await this.assertIdentity(signal)
			const base = `${this.davBase()}calendars/${encodeURIComponent(this.initial.userId)}/`
			const body = '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/"><d:prop><d:displayname/><d:resourcetype/><d:current-user-privilege-set/><c:supported-calendar-component-set/><a:calendar-color/></d:prop></d:propfind>'
			const res = await axios.request<string>({
				url: base,
				method: 'PROPFIND',
				data: body,
				headers: { Depth: '1', 'Content-Type': 'application/xml; charset=utf-8' },
				responseType: 'text',
				signal,
				timeout: 15000,
			})
			if (typeof res.data === 'string' && res.data.length > MAX_DAV_RESPONSE_BYTES) {
				throw new Error('DAV response exceeds maximum allowed size; operation truncated.')
			}
			return parseCalendarsXml(res.data, this.calendarHomeUrl())
		},
		readEvents: async (calendarId: string, signal?: AbortSignal): Promise<DavCalendarObject[]> => {
			await this.assertIdentity(signal)
			const url = `${this.davBase()}calendars/${encodeURIComponent(this.initial.userId)}/${davSegment(calendarId)}/`
			const body = '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"/></c:comp-filter></c:filter></c:calendar-query>'
			const res = await axios.request<string>({
				url,
				method: 'REPORT',
				data: body,
				headers: { Depth: '1', 'Content-Type': 'application/xml; charset=utf-8' },
				responseType: 'text',
				signal,
				timeout: 15000,
			})
			if (typeof res.data === 'string' && res.data.length > MAX_DAV_RESPONSE_BYTES) {
				throw new Error('DAV response exceeds maximum allowed size; operation truncated.')
			}
			return parseCalendarObjectsXml(res.data, 'VEVENT', this.calendarCollectionUrl(calendarId))
		},
		getEvent: async (calendarId: string, filename: string, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = `${this.davBase()}calendars/${encodeURIComponent(this.initial.userId)}/${davSegment(calendarId)}/${davSegment(filename)}`
			const res = await axios.get<string>(url, {
				headers: { Accept: 'text/calendar' },
				responseType: 'text',
				signal,
				timeout: 15000,
			})
			if (typeof res.data === 'string' && res.data.length > MAX_DAV_RESPONSE_BYTES) {
				throw new Error('DAV response exceeds maximum allowed size; operation truncated.')
			}
			const etag = (res.headers.etag ?? '').replace(/^"|"$/g, '').trim()
			if (!etag || etag === '*') throw new Error('Missing revision metadata from calendar response.')
			const raw = res.data
			const values = parseDavProperties(raw, 'VEVENT').values
			return { etag, raw, values }
		},
		createEvent: async (calendarId: string, filename: string, icsData: string, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = `${this.davBase()}calendars/${encodeURIComponent(this.initial.userId)}/${davSegment(calendarId)}/${davSegment(filename)}`
			const res = await axios.put<string>(url, icsData, {
				headers: { 'Content-Type': 'text/calendar; charset=utf-8', 'If-None-Match': '*' },
				signal,
				timeout: 15000,
			})
			const etag = (res.headers.etag ?? '').replace(/^"|"$/g, '')
			return { etag, href: url }
		},
		updateEvent: async (calendarId: string, filename: string, icsData: string, expectedEtag: string, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = `${this.davBase()}calendars/${encodeURIComponent(this.initial.userId)}/${davSegment(calendarId)}/${davSegment(filename)}`
			const etagHeader = strongEtagHeader(expectedEtag)
			const res = await axios.put<string>(url, icsData, {
				headers: { 'Content-Type': 'text/calendar; charset=utf-8', 'If-Match': etagHeader },
				signal,
				timeout: 15000,
			})
			const etag = (res.headers.etag ?? expectedEtag).replace(/^"|"$/g, '')
			return { etag, href: url }
		},
		deleteEvent: async (calendarId: string, filename: string, expectedEtag: string, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = `${this.davBase()}calendars/${encodeURIComponent(this.initial.userId)}/${davSegment(calendarId)}/${davSegment(filename)}`
			const etagHeader = strongEtagHeader(expectedEtag)
			await axios.delete(url, {
				headers: { 'If-Match': etagHeader },
				signal,
				timeout: 15000,
			})
		},
	}

	readonly tasks: TasksApi = {
		listTaskLists: async (signal?: AbortSignal): Promise<DavCalendar[]> => {
			const cals = await this.calendar.listCalendars(signal)
			return cals.filter(c => c.components.includes('VTODO'))
		},
		readTasks: async (calendarId: string, signal?: AbortSignal): Promise<DavCalendarObject[]> => {
			await this.assertIdentity(signal)
			const url = `${this.davBase()}calendars/${encodeURIComponent(this.initial.userId)}/${davSegment(calendarId)}/`
			const body = '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VTODO"/></c:comp-filter></c:filter></c:calendar-query>'
			const res = await axios.request<string>({
				url,
				method: 'REPORT',
				data: body,
				headers: { Depth: '1', 'Content-Type': 'application/xml; charset=utf-8' },
				responseType: 'text',
				signal,
				timeout: 15000,
			})
			if (typeof res.data === 'string' && res.data.length > MAX_DAV_RESPONSE_BYTES) {
				throw new Error('DAV response exceeds maximum allowed size; operation truncated.')
			}
			return parseCalendarObjectsXml(res.data, 'VTODO', this.calendarCollectionUrl(calendarId))
		},
		getTask: async (calendarId: string, filename: string, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = `${this.davBase()}calendars/${encodeURIComponent(this.initial.userId)}/${davSegment(calendarId)}/${davSegment(filename)}`
			const res = await axios.get<string>(url, {
				headers: { Accept: 'text/calendar' },
				responseType: 'text',
				signal,
				timeout: 15000,
			})
			if (typeof res.data === 'string' && res.data.length > MAX_DAV_RESPONSE_BYTES) {
				throw new Error('DAV response exceeds maximum allowed size; operation truncated.')
			}
			const etag = (res.headers.etag ?? '').replace(/^"|"$/g, '').trim()
			if (!etag || etag === '*') throw new Error('Missing revision metadata from task response.')
			const raw = res.data
			const values = parseDavProperties(raw, 'VTODO').values
			return { etag, raw, values }
		},
		createTask: async (calendarId: string, filename: string, icsData: string, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = `${this.davBase()}calendars/${encodeURIComponent(this.initial.userId)}/${davSegment(calendarId)}/${davSegment(filename)}`
			const res = await axios.put<string>(url, icsData, {
				headers: { 'Content-Type': 'text/calendar; charset=utf-8', 'If-None-Match': '*' },
				signal,
				timeout: 15000,
			})
			const etag = (res.headers.etag ?? '').replace(/^"|"$/g, '')
			return { etag, href: url }
		},
		updateTask: async (calendarId: string, filename: string, icsData: string, expectedEtag: string, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = `${this.davBase()}calendars/${encodeURIComponent(this.initial.userId)}/${davSegment(calendarId)}/${davSegment(filename)}`
			const etagHeader = strongEtagHeader(expectedEtag)
			const res = await axios.put<string>(url, icsData, {
				headers: { 'Content-Type': 'text/calendar; charset=utf-8', 'If-Match': etagHeader },
				signal,
				timeout: 15000,
			})
			const etag = (res.headers.etag ?? expectedEtag).replace(/^"|"$/g, '')
			return { etag, href: url }
		},
		deleteTask: async (calendarId: string, filename: string, expectedEtag: string, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = `${this.davBase()}calendars/${encodeURIComponent(this.initial.userId)}/${davSegment(calendarId)}/${davSegment(filename)}`
			const etagHeader = strongEtagHeader(expectedEtag)
			await axios.delete(url, {
				headers: { 'If-Match': etagHeader },
				signal,
				timeout: 15000,
			})
		},
	}

	readonly contacts: ContactsApi = {
		listAddressBooks: async (signal?: AbortSignal): Promise<DavAddressBook[]> => {
			await this.assertIdentity(signal)
			const base = `${this.davBase()}addressbooks/users/${encodeURIComponent(this.initial.userId)}/`
			const body = '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav"><d:prop><d:displayname/><d:resourcetype/><d:current-user-privilege-set/></d:prop></d:propfind>'
			const res = await axios.request<string>({
				url: base,
				method: 'PROPFIND',
				data: body,
				headers: { Depth: '1', 'Content-Type': 'application/xml; charset=utf-8' },
				responseType: 'text',
				signal,
				timeout: 15000,
			})
			if (typeof res.data === 'string' && res.data.length > MAX_DAV_RESPONSE_BYTES) {
				throw new Error('DAV response exceeds maximum allowed size; operation truncated.')
			}
			return parseAddressBooksXml(res.data, this.addressBookHomeUrl())
		},
		findContacts: async (query: string, signal?: AbortSignal): Promise<{ contacts: DavContact[]; complete: boolean }> => {
			await this.assertIdentity(signal)
			const books = await this.contacts.listAddressBooks(signal)
			const found: DavContact[] = []
			for (const book of books.slice(0, 10)) {
				const body = `<c:addressbook-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav"><d:prop><d:getetag/><c:address-data/></d:prop><c:filter><c:prop-filter name="FN"><c:text-match collation="i;unicode-casemap" match-type="contains">${escapeXml(query)}</c:text-match></c:prop-filter></c:filter></c:addressbook-query>`
				const bookTargetUrl = this.addressBookHref(book.href)
				const res = await axios.request<string>({
					url: bookTargetUrl,
					method: 'REPORT',
					data: body,
					headers: { Depth: '1', 'Content-Type': 'application/xml; charset=utf-8' },
					responseType: 'text',
					signal,
					timeout: 15000,
				})
				if (typeof res.data === 'string' && res.data.length > MAX_DAV_RESPONSE_BYTES) {
					throw new Error('DAV response exceeds maximum allowed size; operation truncated.')
				}
				const expectedBookUrl = new URL(bookTargetUrl, typeof location !== 'undefined' ? location.href : 'https://localhost/')
				const contacts = parseContactsXml(res.data, book.title, expectedBookUrl)
				for (const c of contacts) {
					found.push(c)
					if (found.length >= 20) return { contacts: found.slice(0, 20), complete: false }
				}
			}
			return { contacts: found, complete: books.length <= 10 }
		},
		readContact: async (bookId: string, filename?: string, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			if (filename) {
				const url = `${this.davBase()}addressbooks/users/${encodeURIComponent(this.initial.userId)}/${davSegment(bookId)}/${davSegment(filename)}`
				const res = await axios.get<string>(url, {
					headers: { Accept: 'text/vcard' },
					responseType: 'text',
					signal,
					timeout: 15000,
				})
				if (typeof res.data === 'string' && res.data.length > MAX_DAV_RESPONSE_BYTES) {
					throw new Error('DAV response exceeds maximum allowed size; operation truncated.')
				}
				const etag = (res.headers.etag ?? '').replace(/^"|"$/g, '').trim()
				if (!etag || etag === '*') throw new Error('Missing revision metadata from contact response.')
				const raw = res.data
				const values = parseDavProperties(raw, 'VCARD').values
				return { etag, raw, values }
			}
			const url = `${this.davBase()}addressbooks/users/${encodeURIComponent(this.initial.userId)}/${davSegment(bookId)}/`
			const body = '<c:addressbook-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav"><d:prop><d:getetag/><c:address-data/></d:prop></c:addressbook-query>'
			const res = await axios.request<string>({
				url,
				method: 'REPORT',
				data: body,
				headers: { Depth: '1', 'Content-Type': 'application/xml; charset=utf-8' },
				responseType: 'text',
				signal,
				timeout: 15000,
			})
			if (typeof res.data === 'string' && res.data.length > MAX_DAV_RESPONSE_BYTES) {
				throw new Error('DAV response exceeds maximum allowed size; operation truncated.')
			}
			const contacts = parseContactsXml(res.data, bookId, this.addressBookCollectionUrl(bookId))
			return { etag: '', raw: '', values: {}, contacts: contacts.slice(0, 50), complete: contacts.length <= 50 }
		},
		createContact: async (bookId: string, filename: string, vcardData: string, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = `${this.davBase()}addressbooks/users/${encodeURIComponent(this.initial.userId)}/${davSegment(bookId)}/${davSegment(filename)}`
			const res = await axios.put<string>(url, vcardData, {
				headers: { 'Content-Type': 'text/vcard; charset=utf-8', 'If-None-Match': '*' },
				signal,
				timeout: 15000,
			})
			const etag = (res.headers.etag ?? '').replace(/^"|"$/g, '')
			return { etag, href: url }
		},
		updateContact: async (bookId: string, filename: string, vcardData: string, expectedEtag: string, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = `${this.davBase()}addressbooks/users/${encodeURIComponent(this.initial.userId)}/${davSegment(bookId)}/${davSegment(filename)}`
			const etagHeader = strongEtagHeader(expectedEtag)
			const res = await axios.put<string>(url, vcardData, {
				headers: { 'Content-Type': 'text/vcard; charset=utf-8', 'If-Match': etagHeader },
				signal,
				timeout: 15000,
			})
			const etag = (res.headers.etag ?? expectedEtag).replace(/^"|"$/g, '')
			return { etag, href: url }
		},
		deleteContact: async (bookId: string, filename: string, expectedEtag: string, signal?: AbortSignal) => {
			await this.assertIdentity(signal)
			const url = `${this.davBase()}addressbooks/users/${encodeURIComponent(this.initial.userId)}/${davSegment(bookId)}/${davSegment(filename)}`
			const etagHeader = strongEtagHeader(expectedEtag)
			await axios.delete(url, {
				headers: { 'If-Match': etagHeader },
				signal,
				timeout: 15000,
			})
		},
	}

	private addressBookHref(href: string): string {
		const base = this.addressBookHomeUrl()
		const target = new URL(href, base)
		if (target.origin !== base.origin || target.username || target.password || target.search || target.hash || !target.pathname.startsWith(base.pathname)) throw new Error('The native address-book path is unavailable.')
		return target.href
	}

	private historyPath(id: string, digest: string, operation: 'approve' | 'execute' | 'cancel'): string {
		if (!/^[a-f0-9]{48}$/.test(id) || !/^[a-f0-9]{64}$/.test(digest)) throw new Error('Invalid history preview.')
		return `/history/${id}/${operation}`
	}

	private async authenticatedPost<T>(path: string, body: unknown, signal?: AbortSignal): Promise<T> {
		signal?.throwIfAborted()
		await this.assertIdentity(signal)
		if (!this.#cloudIdentity) throw new Error('Sign in to Fadenstack again.')
		const token = await this.#cloudIdentity(signal)
		signal?.throwIfAborted()
		const url = generateUrl(`/apps/fadenstack_agent${path}`)
		if (new URL(url, location.href).origin !== location.origin) throw new Error('The Nextcloud request origin changed.')
		const response = await fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			redirect: 'error',
			cache: 'no-store',
			headers: { 'Content-Type': 'application/json', requesttoken: getRequestToken() ?? '', 'X-Fadenstack-Session': this.initial.binding, 'X-Fadenstack-Agent-Token': token },
			body: JSON.stringify(body),
			signal: signal ? AbortSignal.any([signal, AbortSignal.timeout(20000)]) : AbortSignal.timeout(20000),
		})
		const data = await response.json() as T & { message?: unknown, code?: unknown }
		if (!response.ok) {
			const message = response.status === 409 && typeof data.code === 'string' && typeof HISTORY_CONFLICTS[data.code] === 'string'
				? HISTORY_CONFLICTS[data.code]!
				: typeof data.message === 'string' ? data.message : 'The Nextcloud operation could not be confirmed.'
			throw Object.assign(new Error(message), { response: { status: response.status, data: { ...data, message } } })
		}
		return data
	}

	readonly searchTransport: SearchTransport = {
		plan: (filters, signal) => this.post<SearchPlan>('/search/plan', { filters }, signal),
		resolve: (resourceIds, scope, signal, content = false) => this.post<FileMetadata[]>('/search/resolve', { resourceIds, scope, content }, signal),
		capabilities: signal => this.post<SearchCapabilities>('/search/capabilities', {}, signal),
		content: (query, limit, page, signal) => this.post<NativeContentResults>('/search/content', { query, limit, page }, signal),
		dav: async (xml, signal) => {
			await this.assertIdentity(signal)
			const response = await axios.request<string>({
				url: generateRemoteUrl('dav/'),
				method: 'SEARCH',
				data: xml,
				headers: { 'Content-Type': 'text/xml; charset=utf-8' },
				responseType: 'text',
				signal,
				timeout: 15000,
			})
			if (response.status !== 207) throw new Error('Native WebDAV SEARCH is unavailable on this Nextcloud server.')
			return response.data
		},
	}

	async identity(signal?: AbortSignal): Promise<Identity> {
		return (await axios.get<Identity>(generateUrl('/apps/fadenstack_agent/config'), { timeout: 10000, signal })).data
	}

	async assertIdentity(signal?: AbortSignal): Promise<void> {
		const now = await this.identity(signal)
		if (now.userId !== this.initial.userId || now.binding !== this.initial.binding
			|| now.historyEnabled !== this.initial.historyEnabled
			|| now.development !== this.initial.development
			|| (['extendedFilesEnabled', 'tablesEnabled', 'deckEnabled', 'calendarEnabled', 'tasksEnabled', 'contactsEnabled'] as const).some(key => now[key] !== this.initial[key])
			|| now.scopePolicy?.version !== this.initial.scopePolicy?.version
			|| now.serverUrl !== this.initial.serverUrl || now.agent !== this.initial.agent || !now.enabled) {
			throw new Error('The Nextcloud session or agent configuration changed. Reload and sign in again.')
		}
	}

	async post<T>(path: string, body: unknown, signal?: AbortSignal): Promise<T> {
		if (this.initial.historyEnabled && (/^\/proposals(?:\/|$)/.test(path) || path === '/creations/finish')) return this.authenticatedPost(path, body, signal)
		return (await axios.post<T>(generateUrl(`/apps/fadenstack_agent${path}`), body, {
			headers: { 'X-Fadenstack-Session': this.initial.binding }, signal, timeout: 20000,
		})).data
	}

	select(fileId: number, signal?: AbortSignal): Promise<FileContext> { return this.post('/context', { fileId }, signal) }
	scopeResource(resourceId: string, purpose: ResourcePurpose, signal: AbortSignal): Promise<NativeResource> { return this.post('/scope/resource', { resourceId, purpose }, signal) }
	read(contextId: string, signal?: AbortSignal): Promise<ReadResult> { return this.post('/context/read', { contextId }, signal) }
	list(contextId: string, signal?: AbortSignal): Promise<Listing> { return this.post('/context/list', { contextId }, signal) }
	propose(contextId: string, args: WriteArguments, signal?: AbortSignal): Promise<Proposal> {
		return this.post('/proposals', { contextId, ...args }, signal)
	}

	async approve(id: string, signal?: AbortSignal): Promise<void> { await this.post(`/proposals/${encodeURIComponent(id)}/approve`, {}, signal) }
	async commit(id: string, signal?: AbortSignal): Promise<WriteResult> {
		const result = await this.post<WriteResult | DavCreatePlan>(`/proposals/${encodeURIComponent(id)}/commit`, { createProtocol: 1 }, signal)
		if (!('kind' in result)) return result
		const prefix = `/files/${encodeURIComponent(this.initial.userId)}/`
		if (result.kind !== 'dav-create' || !result.dav_path.startsWith(prefix) || /[?#\r\n]/.test(result.dav_path)) throw new Error('Invalid approved Nextcloud creation plan.')
		await this.assertIdentity(signal)
		const [parent] = await this.searchTransport.resolve([result.parent_resource_id], 'all', signal ?? AbortSignal.timeout(20000))
		if (!parent?.folder || parent.path !== result.parent_path || parent.etag !== result.parent_etag || !(parent.permissions & 4)) throw new Error('The approved destination changed. No write was performed.')
		const put = await axios.put(generateRemoteUrl('dav') + result.dav_path, result.content, {
			headers: { 'Content-Type': 'text/plain; charset=utf-8', 'If-None-Match': '*', 'X-OC-CTime': String(Math.floor(Date.now() / 1000)) }, signal, timeout: 20000,
		})
		if (put.status !== 201) throw new Error('Nextcloud did not confirm a new file creation. No success is claimed.')
		await this.assertIdentity(signal)
		return this.post('/creations/finish', { receiptId: result.receiptId }, signal)
	}

	async pdf(contextId: string, signal?: AbortSignal): Promise<Uint8Array> {
		const response = await axios.post<ArrayBuffer>(generateUrl('/apps/fadenstack_agent/context/content'), { contextId }, {
			headers: { 'X-Fadenstack-Session': this.initial.binding }, responseType: 'arraybuffer', signal, timeout: 20000,
		})
		return new Uint8Array(response.data)
	}

	async getAppCapabilities(signal?: AbortSignal): Promise<AppCapabilities> {
		await this.assertIdentity(signal)
		const response = await axios.get<{ ocs: { data: { capabilities: Record<string, unknown> } } }>(
			generateUrl('/ocs/v2.php/cloud/capabilities?format=json'),
			{ headers: { 'OCS-APIRequest': 'true' }, signal, timeout: 10000 },
		)
		return response.data?.ocs?.data?.capabilities ?? {}
	}

	async createFolder(folderPath: string, name: string, signal?: AbortSignal): Promise<FolderCreateResult> {
		await this.assertIdentity(signal)
		if (typeof name !== 'string' || !name.trim() || name.length > 100 || /[\x00-\x1f\x7f/\\:<>"|?*]/.test(name) || name === '.' || name === '..') {
			throw new Error('A valid folder name up to 100 characters without path separators is required.')
		}
		const cleanFolder = davFilePath(folderPath)
		const targetRel = cleanFolder ? `${cleanFolder}/${encodeURIComponent(name)}` : encodeURIComponent(name)
		const davUrl = `${generateRemoteUrl('dav')}files/${encodeURIComponent(this.initial.userId)}/${targetRel}`

		try {
			const check = await axios.request({ url: davUrl, method: 'PROPFIND', headers: { Depth: '0' }, signal, timeout: 10000 })
			if (check.status === 200 || check.status === 207) {
				throw new Error('Destination folder already exists; overwriting is excluded.')
			}
		} catch (error) {
			const status = (error as { response?: { status?: number } })?.response?.status
			if (status !== 404) throw error
		}

		const response = await axios.request({
			url: davUrl,
			method: 'MKCOL',
			signal,
			timeout: 15000,
		})
		if (response.status !== 201) throw new Error('Nextcloud did not confirm folder creation.')
		return { path: `/${targetRel}`, name }
	}

	async copyFile(sourcePath: string, destinationPath: string, expectedEtag: string, signal?: AbortSignal): Promise<FileCopyMoveResult> {
		return this.copyOrMove('COPY', sourcePath, destinationPath, expectedEtag, signal)
	}

	async moveFile(sourcePath: string, destinationPath: string, expectedEtag: string, signal?: AbortSignal): Promise<FileCopyMoveResult> {
		return this.copyOrMove('MOVE', sourcePath, destinationPath, expectedEtag, signal)
	}

	private async copyOrMove(method: 'COPY' | 'MOVE', sourcePath: string, destinationPath: string, expectedEtag: string, signal?: AbortSignal): Promise<FileCopyMoveResult> {
		await this.assertIdentity(signal)
		if (!expectedEtag) throw new Error('The expected source ETag is required.')
		const cleanSource = davFilePath(sourcePath)
		const destination = !destinationPath.startsWith('/') && !destinationPath.includes('/')
			? `${sourcePath.slice(0, sourcePath.lastIndexOf('/') + 1)}${destinationPath}`
			: destinationPath
		const cleanDest = davFilePath(destination)
		if (!cleanSource || !cleanDest) throw new Error('Valid source and destination paths are required.')
		if (cleanSource === cleanDest) throw new Error('Choose a different destination.')

		const baseDav = `${generateRemoteUrl('dav')}files/${encodeURIComponent(this.initial.userId)}/`
		const sourceUrl = `${baseDav}${cleanSource}`
		const destUrl = `${baseDav}${cleanDest}`

		try {
			const check = await axios.request({ url: destUrl, method: 'PROPFIND', headers: { Depth: '0' }, signal, timeout: 10000 })
			if (check.status === 200 || check.status === 207) {
				throw new Error('Destination exists; overwriting is excluded.')
			}
		} catch (error) {
			const status = (error as { response?: { status?: number } })?.response?.status
			if (status !== 404) throw error
		}

		const fullDest = destUrl.startsWith('http') ? destUrl : (typeof location !== 'undefined' ? `${location.origin}${destUrl}` : destUrl)
		const response = await axios.request({
			url: sourceUrl,
			method,
			headers: {
				Destination: fullDest,
				Overwrite: 'F',
				'If-Match': strongEtagHeader(expectedEtag),
			},
			signal,
			timeout: 20000,
		})
		if (response.status !== 201 && response.status !== 204) {
			throw new Error(`Nextcloud did not confirm ${method === 'COPY' ? 'copying' : 'moving'} the file.`)
		}
		return { operation: method === 'COPY' ? 'copy' : 'move', source: `/${cleanSource}`, destination: `/${cleanDest}` }
	}

	async sharesRead(path: string, signal?: AbortSignal): Promise<ShareEntry[]> {
		await this.assertIdentity(signal)
		const clean = '/' + path.replace(/^\/+/, '')
		const url = generateUrl(`/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json&path=${encodeURIComponent(clean)}`)
		const response = await axios.get<{ ocs: { data: unknown[] } }>(url, {
			headers: { 'OCS-APIRequest': 'true' },
			signal,
			timeout: 15000,
		})
		const raw = (Array.isArray(response.data?.ocs?.data) ? response.data.ocs.data : []) as Record<string, unknown>[]
		return raw.map(s => ({
			id: Number(s.id),
			shareType: Number(s.share_type ?? s.shareType),
			shareWith: typeof (s.share_with ?? s.shareWith) === 'string' ? String(s.share_with ?? s.shareWith) : undefined,
			shareWithDisplayName: typeof (s.share_with_displayname ?? s.shareWithDisplayName) === 'string' ? String(s.share_with_displayname ?? s.shareWithDisplayName) : undefined,
			permissions: Number(s.permissions),
			path: String(s.path ?? clean),
			fileTarget: typeof (s.file_target ?? s.fileTarget) === 'string' ? String(s.file_target ?? s.fileTarget) : undefined,
			expiration: typeof s.expiration === 'string' ? s.expiration : null,
			mailSend: s.mail_send === 1 || s.sendMail === true,
		}))
	}

	async shareReadonly(path: string, recipientUserId: string, expectedEtag: string, signal?: AbortSignal): Promise<ShareEntry> {
		await this.assertIdentity(signal)
		const revision = strongEtagHeader(expectedEtag)
		const targetUrl = `${generateRemoteUrl('dav')}files/${encodeURIComponent(this.initial.userId)}/${davFilePath(path)}`
		const current = await axios.request<string>({ url: targetUrl, method: 'PROPFIND', data: '<d:propfind xmlns:d="DAV:"><d:prop><d:getetag/></d:prop></d:propfind>', headers: { Depth: '0', 'Content-Type': 'application/xml' }, responseType: 'text', signal, timeout: 10000 })
		const doc = parseXmlDoc(current.data)
		const observed = doc ? findElements(doc, 'DAV:', 'getetag')[0]?.textContent?.trim() : undefined
		if (current.status !== 207 || !observed || strongEtagHeader(observed) !== revision) throw new Error('The share target revision changed. No share was sent.')
		if (!recipientUserId || typeof recipientUserId !== 'string' || recipientUserId.length > 80) {
			throw new Error('A valid recipient Nextcloud user ID is required.')
		}
		const clean = '/' + path.replace(/^\/+/, '')
		const url = generateUrl('/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json')
		const response = await axios.post<{ ocs: { data: Record<string, unknown> } }>(
			url,
			{
				path: clean,
				shareType: 0,
				shareWith: recipientUserId,
				permissions: 1,
				sendMail: false,
			},
			{
				headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
				signal,
				timeout: 20000,
			},
		)
		const s = response.data?.ocs?.data ?? {}
		const permissions = Number(s.permissions)
		if (permissions !== 1) {
			throw new Error('Share could not be verified as read-only.')
		}
		return {
			id: Number(s.id),
			shareType: Number(s.share_type ?? s.shareType ?? 0),
			shareWith: String(s.share_with ?? s.shareWith ?? recipientUserId),
			shareWithDisplayName: typeof (s.share_with_displayname ?? s.shareWithDisplayName) === 'string' ? String(s.share_with_displayname ?? s.shareWithDisplayName) : undefined,
			permissions: 1,
			path: String(s.path ?? clean),
			fileTarget: typeof (s.file_target ?? s.fileTarget) === 'string' ? String(s.file_target ?? s.fileTarget) : undefined,
			expiration: typeof s.expiration === 'string' ? s.expiration : null,
			mailSend: false,
		}
	}

	async organizeRead(folderPath?: string, signal?: AbortSignal): Promise<FolderListing> {
		await this.assertIdentity(signal)
		const cleanFolder = davFilePath(folderPath ?? '')
		const davUrl = `${generateRemoteUrl('dav')}files/${encodeURIComponent(this.initial.userId)}/${cleanFolder ? cleanFolder + '/' : ''}`
		const propfindXml = '<d:propfind xmlns:d="DAV:"><d:prop><d:displayname/><d:getetag/><d:resourcetype/></d:prop></d:propfind>'
		const response = await axios.request<string>({
			url: davUrl,
			method: 'PROPFIND',
			data: propfindXml,
			headers: { Depth: '1', 'Content-Type': 'application/xml' },
			responseType: 'text',
			signal,
			timeout: 15000,
		})
		const entries: FolderListingEntry[] = []
		if (typeof DOMParser !== 'undefined') {
			const doc = new DOMParser().parseFromString(response.data, 'application/xml')
			const responses = doc.getElementsByTagNameNS('DAV:', 'response')
			for (let i = 0; i < responses.length; i++) {
				const r = responses[i]
				const href = r?.getElementsByTagNameNS('DAV:', 'href')[0]?.textContent ?? ''
				const filename = r?.getElementsByTagNameNS('DAV:', 'displayname')[0]?.textContent ?? href.replace(/\/$/, '').split('/').pop() ?? ''
				const etag = (r?.getElementsByTagNameNS('DAV:', 'getetag')[0]?.textContent ?? '').replace(/^"|"$/g, '')
				const isDir = (r?.getElementsByTagNameNS('DAV:', 'collection').length ?? 0) > 0
				entries.push({ href, filename, etag, folder: isDir })
			}
		}
		return { directory: `/${cleanFolder}`, entries: entries.slice(0, 50) }
	}

}

export function errorMessage(error: unknown): string {
	const response = (error as { response?: { data?: { message?: unknown } } })?.response
	return typeof response?.data?.message === 'string'
		? response.data.message
		: error instanceof Error ? error.message : 'The operation failed.'
}

function parseXmlDoc(raw: string): Document | null {
	if (typeof DOMParser === 'undefined') return null
	const doc = new DOMParser().parseFromString(raw, 'application/xml')
	if (doc.querySelector('parsererror')) return null
	return doc
}

function findElements(parent: Document | Element, ns: string, localName: string): Element[] {
	const list = Array.from(parent.getElementsByTagNameNS(ns, localName))
	if (list.length > 0) return list
	return Array.from(parent.getElementsByTagName(localName)).concat(
		Array.from(parent.getElementsByTagName(`d:${localName}`)),
		Array.from(parent.getElementsByTagName(`c:${localName}`)),
		Array.from(parent.getElementsByTagName(`cal:${localName}`)),
		Array.from(parent.getElementsByTagName(`card:${localName}`)),
	)
}

function getElemText(parent: Element, ns: string, localName: string): string {
	const el = findElements(parent, ns, localName)[0]
	return el?.textContent?.trim() ?? ''
}

function escapeXml(value: string): string {
	return value.replace(/[&<>"']/g, ch => ({
		'&': '&amp;',
		'<': '&lt;',
		'>': '&gt;',
		'"': '&quot;',
		"'": '&apos;',
	}[ch]!))
}

function validateDavHref(href: string, expectedParentUrl?: URL): string {
	if (!href || typeof href !== 'string') throw new Error('Missing or invalid DAV href.')
	const base = expectedParentUrl ?? (typeof location !== 'undefined' ? new URL(location.href) : new URL('https://localhost/'))
	let target: URL
	try {
		target = new URL(href, base)
	} catch {
		throw new Error('Malformed DAV href.')
	}
	if (expectedParentUrl) {
		if (target.origin !== expectedParentUrl.origin) {
			throw new Error(`DAV href origin mismatch: ${target.origin} does not match expected ${expectedParentUrl.origin}`)
		}
		if (target.username || target.password) {
			throw new Error('DAV href contains disallowed userinfo or credentials.')
		}
		if (target.search || target.hash) {
			throw new Error('DAV href contains disallowed query or fragment parameters.')
		}
		const expectedPath = expectedParentUrl.pathname.replace(/\/$/, '')
		const targetPath = target.pathname.replace(/\/$/, '')
		if (targetPath !== expectedPath && !targetPath.startsWith(expectedPath + '/')) {
			throw new Error(`DAV href outside expected collection: ${target.pathname} is outside ${expectedParentUrl.pathname}`)
		}
	}
	return target.pathname
}

function findSuccessfulPropstats(responseEl: Element): Element[] {
	const propstats = findElements(responseEl, 'DAV:', 'propstat')
	const successfulProps: Element[] = []
	for (const ps of propstats) {
		const statusEl = findElements(ps, 'DAV:', 'status')[0]
		const status = statusEl?.textContent?.trim()
		if (!status || status.includes('200')) {
			const propEl = findElements(ps, 'DAV:', 'prop')[0]
			if (propEl) successfulProps.push(propEl)
		}
	}
	return successfulProps
}

function getPropText(props: Element[], ns: string, localName: string): string {
	for (const prop of props) {
		const text = getElemText(prop, ns, localName)
		if (text) return text
	}
	return ''
}

function hasPropElement(props: Element[], ns: string, localName: string): boolean {
	for (const prop of props) {
		if (findElements(prop, ns, localName).length > 0) return true
	}
	return false
}

function parseCalendarsXml(xmlText: string, expectedParentUrl?: URL): DavCalendar[] {
	const doc = parseXmlDoc(xmlText)
	if (!doc) return []
	const responses = findElements(doc, 'DAV:', 'response')
	const result: DavCalendar[] = []
	for (const r of responses) {
		const props = findSuccessfulPropstats(r)
		if (!props.length) continue
		const isCalendar = hasPropElement(props, 'urn:ietf:params:xml:ns:caldav', 'calendar')
		if (!isCalendar) continue
		const rawHref = getElemText(r, 'DAV:', 'href')
		const validPath = validateDavHref(rawHref, expectedParentUrl)
		if (!validPath) continue
		const id = decodeURIComponent(validPath.replace(/\/$/, '').split('/').pop() ?? '')
		if (!id) continue
		const title = getPropText(props, 'DAV:', 'displayname') || id
		const comps: string[] = []
		for (const prop of props) {
			for (const c of findElements(prop, 'urn:ietf:params:xml:ns:caldav', 'comp')) {
				const name = c.getAttribute('name')
				if (name && !comps.includes(name)) comps.push(name)
			}
		}
		const canWrite = hasPropElement(props, 'DAV:', 'write')
		const color = getPropText(props, 'http://apple.com/ns/ical/', 'calendar-color')
		result.push({
			id,
			title,
			href: validPath,
			components: comps.length ? comps : ['VEVENT', 'VTODO'],
			canWrite,
			color: color || undefined,
		})
		if (result.length >= 50) break
	}
	return result
}

type JCalData = ConstructorParameters<typeof ICAL.Component>[0]

interface DavParsedTime {
	zone?: { tzid?: string }
	isUtc?: boolean
	isDate?: boolean
	timezone?: string
	toString(): string
}

function parseCalendarObjectsXml(xmlText: string, component: 'VEVENT' | 'VTODO', expectedParentUrl?: URL): DavCalendarObject[] {
	const doc = parseXmlDoc(xmlText)
	if (!doc) return []
	const responses = findElements(doc, 'DAV:', 'response')
	const result: DavCalendarObject[] = []
	for (const r of responses) {
		const rawHref = getElemText(r, 'DAV:', 'href')
		const validPath = validateDavHref(rawHref, expectedParentUrl)
		if (!validPath) continue

		// Collections are collections, not leaf calendar objects
		const isCollection = hasPropElement(findElements(r, 'DAV:', 'prop'), 'DAV:', 'collection') || validPath.endsWith('/')
		if (isCollection) continue

		const props = findSuccessfulPropstats(r)
		if (!props.length) continue

		const rawEtag = getPropText(props, 'DAV:', 'getetag')
		const etag = rawEtag.replace(/^"|"$/g, '').trim()
		if (!etag) continue

		const rawData = getPropText(props, 'urn:ietf:params:xml:ns:caldav', 'calendar-data')
		if (!rawData) continue

		const filename = decodeURIComponent(validPath.split('/').pop() ?? '')
		if (!filename.endsWith('.ics')) continue

		try {
			const jcal = ICAL.parse(rawData)
			const comp = new ICAL.Component(jcal as unknown as JCalData)
			const sub = comp.getFirstSubcomponent(component.toLowerCase())
			if (!sub) continue

			const dtstartProp = sub.getFirstProperty('dtstart')
			const dtendProp = sub.getFirstProperty('dtend')
			const dueProp = sub.getFirstProperty('due')

			let startUtc: string | undefined
			let startDate: string | undefined
			let endUtc: string | undefined
			let endDate: string | undefined
			let timezone: string | undefined
			let dueDate: string | undefined
			let dueUtc: string | undefined

			if (dtstartProp) {
				const time = dtstartProp.getFirstValue() as unknown as DavParsedTime
				const tzid = dtstartProp.getParameter('tzid') || (time.zone && time.zone.tzid !== 'floating' && time.zone.tzid !== 'UTC' ? time.zone.tzid : undefined)
				if (time.zone?.tzid === 'UTC' || time.isUtc) {
					startUtc = time.toString()
				} else {
					startDate = time.toString()
					timezone = tzid || 'floating'
				}
			}
			if (dtendProp) {
				const time = dtendProp.getFirstValue() as unknown as DavParsedTime
				if (time.zone?.tzid === 'UTC' || time.isUtc) {
					endUtc = time.toString()
				} else {
					endDate = time.toString()
				}
			}
			if (dueProp) {
				const time = dueProp.getFirstValue() as unknown as DavParsedTime
				if (time.isDate) {
					dueDate = time.toString()
				} else if (time.zone?.tzid === 'UTC' || time.isUtc) {
					dueUtc = time.toString()
				} else {
					dueDate = time.toString()
					timezone = dueProp.getParameter('tzid') || time.timezone || 'floating'
				}
			}

			const summary = sub.getFirstPropertyValue('summary')
			const description = sub.getFirstPropertyValue('description')
			const location = sub.getFirstPropertyValue('location')
			const status = sub.getFirstPropertyValue('status')
			const percentComplete = sub.getFirstPropertyValue('percent-complete')
			const priority = sub.getFirstPropertyValue('priority')
			const uid = sub.getFirstPropertyValue('uid')

			const isRecurring = !!sub.getFirstProperty('rrule')
				|| !!sub.getFirstProperty('recurrence-id')
				|| !!sub.getFirstProperty('rdate')
				|| !!sub.getFirstProperty('exdate')
				|| comp.getAllSubcomponents(component.toLowerCase()).length > 1

			const hasAttendees = !!sub.getFirstProperty('attendee') || !!sub.getFirstProperty('organizer')

			const propertyParameters: Record<string, string> = {}
			for (const prop of sub.getAllProperties()) {
				const key = prop.name.toUpperCase()
				const line = prop.toICALString()
				const colonIdx = line.indexOf(':')
				if (colonIdx > 0) {
					propertyParameters[key] = line.slice(0, colonIdx)
				}
			}

			result.push({
				href: validPath,
				filename,
				etag,
				summary: typeof summary === 'string' ? summary : undefined,
				description: typeof description === 'string' ? description : undefined,
				location: typeof location === 'string' ? location : undefined,
				startUtc,
				startDate,
				endUtc,
				endDate,
				timezone,
				dueDate,
				dueUtc,
				status: typeof status === 'string' ? status : undefined,
				percentComplete: percentComplete !== undefined && percentComplete !== null ? String(percentComplete) : undefined,
				priority: priority !== undefined && priority !== null ? String(priority) : undefined,
				uid: typeof uid === 'string' ? uid : undefined,
				recurring: isRecurring,
				hasAttendees,
				propertyParameters,
			})
			if (result.length >= 50) break
		} catch {
			continue
		}
	}
	return result
}

function parseAddressBooksXml(xmlText: string, expectedParentUrl?: URL): DavAddressBook[] {
	const doc = parseXmlDoc(xmlText)
	if (!doc) return []
	const responses = findElements(doc, 'DAV:', 'response')
	const result: DavAddressBook[] = []
	for (const r of responses) {
		const props = findSuccessfulPropstats(r)
		if (!props.length) continue
		const isBook = hasPropElement(props, 'urn:ietf:params:xml:ns:carddav', 'addressbook')
		if (!isBook) continue
		const rawHref = getElemText(r, 'DAV:', 'href')
		const validPath = validateDavHref(rawHref, expectedParentUrl)
		if (!validPath) continue
		const id = decodeURIComponent(validPath.replace(/\/$/, '').split('/').pop() ?? '')
		if (!id) continue
		const title = getPropText(props, 'DAV:', 'displayname') || id
		const canWrite = hasPropElement(props, 'DAV:', 'write')
		result.push({ id, title, href: validPath, canWrite })
		if (result.length >= 10) break
	}
	return result
}

function parseContactsXml(xmlText: string, bookTitle: string, expectedParentUrl?: URL): DavContact[] {
	const doc = parseXmlDoc(xmlText)
	if (!doc) return []
	const responses = findElements(doc, 'DAV:', 'response')
	const result: DavContact[] = []
	for (const r of responses) {
		const rawHref = getElemText(r, 'DAV:', 'href')
		const validPath = validateDavHref(rawHref, expectedParentUrl)
		if (!validPath) continue

		// Collections are not leaf contacts
		const isCollection = hasPropElement(findElements(r, 'DAV:', 'prop'), 'DAV:', 'collection') || validPath.endsWith('/')
		if (isCollection) continue

		const props = findSuccessfulPropstats(r)
		if (!props.length) continue

		const rawEtag = getPropText(props, 'DAV:', 'getetag')
		const etag = rawEtag.replace(/^"|"$/g, '').trim()
		if (!etag) continue

		const rawData = getPropText(props, 'urn:ietf:params:xml:ns:carddav', 'address-data')
		if (!rawData) continue

		const filename = decodeURIComponent(validPath.split('/').pop() ?? '')
		if (!filename.endsWith('.vcf')) continue

		try {
			const jcal = ICAL.parse(rawData)
			const comp = new ICAL.Component(jcal as unknown as JCalData)
			if (comp.name !== 'vcard') continue

			const uid = comp.getFirstPropertyValue('uid')
			const fn = comp.getFirstPropertyValue('fn')
			const email = comp.getFirstPropertyValue('email')
			const tel = comp.getFirstPropertyValue('tel')
			const note = comp.getFirstPropertyValue('note')
			const catProp = comp.getFirstProperty('categories')
			const categories = catProp ? (catProp.getValues().join(',') || catProp.getFirstValue()) : undefined

			result.push({
				href: validPath,
				filename,
				book: bookTitle,
				uid: typeof uid === 'string' ? uid : undefined,
				name: typeof fn === 'string' ? fn : undefined,
				email: typeof email === 'string' ? email : undefined,
				tel: typeof tel === 'string' ? tel : undefined,
				note: typeof note === 'string' ? note : undefined,
				categories: typeof categories === 'string' ? categories : undefined,
				etag,
			})
			if (result.length >= 50) break
		} catch {
			continue
		}
	}
	return result
}
