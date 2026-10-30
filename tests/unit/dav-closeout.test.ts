import { afterEach, describe, expect, it, vi } from 'vitest'
import { FakeGateway, lastToolResults } from '@fadenstack/testing'
import { ApprovalPart } from '@fadenstack/ui'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { ProviderReadRuntime } from '../../src/providers/readRuntime.ts'
import { NativeViewGuard } from '../../src/workspace/nativeView.ts'
import { WorkspaceCoordinator } from '../../src/workspace/coordinator.ts'
import type { NativeProvidersApi } from '../../src/providers/client.ts'
import type { FileApi, Identity } from '../../src/integrations/nextcloud/types.ts'
import type { ScopePolicy, PublishedTool } from '../../src/platform/scope.ts'
import { TurnBudget } from '../../src/platform/limits.ts'
import captured from './dav-closeout-fixture.json'
import { displayDavDate, nativeCalendarDate } from '../../src/providers/davDate.ts'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => `${location.origin}${path}` }))
vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'synthetic-csrf' }))
vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, value: string) => value }))

const names = captured.capabilities.flatMap(cap => cap.tools)
const published = names.map(name => ({ name, risk: /_(create|patch|delete)$/.test(name) ? 'destructive' : 'read', individualApproval: /_(create|patch|delete)$/.test(name) })) as PublishedTool[]
const policy: ScopePolicy = {
	version: 'diagnostic-policy', gatewayOrigin: 'https://faden.test', sources: [{ kind: 'user-files' }], destinations: [],
	tools: names, sourceClasses: ['standard'], downstreamPolicy: 'unverified', destinationAudiences: [], movements: [], developmentLoopback: true,
}
const identity: Identity = { userId: 'synthetic-owner', binding: 'diagnostic-native-session', enabled: true,
	serverUrl: 'https://faden.test', agent: 'dav-diagnostic', development: true, providerReadsEnabled: true, scopePolicy: policy }
const cleanups: (() => void | Promise<void>)[] = []
afterEach(async () => { for (const cleanup of cleanups.splice(0)) await cleanup(); document.body.replaceChildren(); history.replaceState(null, '', '/') })
function native(): NativeProvidersApi {
	const propose = async (operation: string, collectionId: string, filename: string) => ({ id: 'proposal-1', operation, toolName: 'diagnostic', target: filename, collectionId, filename, hash: 'exact-payload-digest', expires: Date.now() + 600000 })
	const commit = async () => ({ status: 'saved', outcome: 'verified', etag: 'new-revision', target: 'synthetic' })
	return {
		capabilities: vi.fn(async () => captured.capabilities),
		calendarCollections: vi.fn(async () => captured.calendarCollections), calendarEvents: vi.fn(async () => captured.calendarEvents),
		calendarEvent: vi.fn(async () => ({ etag: captured.calendarEvents[0].revision.replace(/^"|"$/g, ''), content: captured.marker.calendar.content })),
		calendarPropose: vi.fn(propose), calendarApprove: vi.fn(async () => ({ approved: true })), calendarCommit: vi.fn(commit),
		tasksLists: vi.fn(async () => captured.tasksLists), tasksItems: vi.fn(async () => captured.tasks),
		taskItem: vi.fn(async () => ({ etag: captured.tasks[0].revision.replace(/^"|"$/g, ''), content: captured.marker.tasks.content })),
		tasksPropose: vi.fn(propose), tasksApprove: vi.fn(async () => ({ approved: true })), tasksCommit: vi.fn(commit),
		contactsBooks: vi.fn(async () => captured.books), contactsFind: vi.fn(async () => captured.found),
		contactsRead: vi.fn(async (_book, filename) => filename ? captured.contact : { contacts: [captured.contact], complete: true }),
		contactsPropose: vi.fn(propose), contactsApprove: vi.fn(async () => ({ approved: true })), contactsCommit: vi.fn(commit),
	} as NativeProvidersApi
}
function files(api: NativeProvidersApi): FileApi {
	return { identity: vi.fn(async () => identity), assertIdentity: vi.fn(async () => {}), scopeResource: vi.fn(),
		select: vi.fn(), read: vi.fn(), pdf: vi.fn(), list: vi.fn(), propose: vi.fn(), approve: vi.fn(), commit: vi.fn(), nativeProviders: api }
}
function gateway(model: ConstructorParameters<typeof FakeGateway>[0]['model']) {
	return new FakeGateway({ agents: [{ slug: identity.agent, version: 1, contract: published }], users: [{ email: 'owner', password: 'test', agents: [identity.agent] }], model })
}
function workspace(app: string) {
	history.replaceState(null, '', '/apps/' + app + '/')
	document.body.innerHTML = '<main id="app-content-vue"><div class="files-list"></div></main>'
	const guard = new NativeViewGuard()
	const refresh = vi.fn(async () => 'refreshed' as const)
	const coordinator = new WorkspaceCoordinator({ guard, refresh })
	cleanups.push(() => coordinator.dispose())
	return { guard, coordinator, refresh }
}
async function settle(host: NextcloudAgent, promise: Promise<unknown>) {
	let done = false
	const completed = promise.finally(() => { done = true })
	let approval: ApprovalPart | undefined
	await vi.waitFor(() => {
		approval = host.chat.turns.flatMap(turn => [...turn.parts]).find(part => part instanceof ApprovalPart && part.isPending) as ApprovalPart | undefined
		expect(done || !!approval).toBe(true)
	})
	if (approval) await host.approve(approval)
	await completed
}

describe('Native DAV closeout through the published SDK using captured native DTO shapes', () => {
	it('routes compact native dates and previews UTC, floating and all-day values without changing their meaning', () => {
		expect(nativeCalendarDate('20280201T090000Z')).toBe('2028-02-01')
		expect(nativeCalendarDate('2028-02-01T09:00:00Z')).toBe('2028-02-01')
		expect(displayDavDate('20280201T090000Z')).toEqual({ text: '2028-02-01 09:00 UTC', allDay: false })
		expect(displayDavDate('20280201T090000')).toEqual({ text: '2028-02-01 09:00', allDay: false })
		expect(displayDavDate('20280201')).toEqual({ text: '2028-02-01', allDay: true })
	})

	it('Tasks-only discovery never calls the unavailable Calendar collections endpoint or offers event tools', async () => {
		const api = native(), runtime = new ProviderReadRuntime(api)
		cleanups.push(() => runtime.dispose())
		vi.mocked(api.capabilities).mockResolvedValue([
			{ ...captured.capabilities[0], tools: ['nc_calendar_list'], discoveryOnly: true, conflict: 'read-only', canWrite: false },
			captured.capabilities.find(cap => cap.provider === 'tasks')!,
		])
		const signal = new AbortController().signal
		await runtime.prepare('tasks-only', published, policy, new TurnBudget(), () => {}, signal)
		await runtime.collections(signal)
		expect(api.calendarCollections).not.toHaveBeenCalled()
		expect(api.tasksLists).toHaveBeenCalledTimes(1)
		expect(runtime.offeredTools).toContain('nc_calendar_list')
		expect(runtime.offeredTools).not.toContain('nc_calendar_read')
	})

	it('shared Calendar discovery includes authorized VTODO lists', async () => {
		const api = native(), runtime = new ProviderReadRuntime(api)
		cleanups.push(() => runtime.dispose())
		await runtime.prepare('discovery', published, policy, new TurnBudget(), () => {}, new AbortController().signal)
		const result = await runtime.collections(new AbortController().signal)
		expect(JSON.stringify(result)).toContain(captured.marker.tasks.collection)
		expect(api.tasksLists).toHaveBeenCalledTimes(1)
	})

	it('Calendar tool projection retains the native filename needed for mutation', async () => {
		const runtime = new ProviderReadRuntime(native())
		cleanups.push(() => runtime.dispose())
		const signal = new AbortController().signal
		await runtime.prepare('projection', published, policy, new TurnBudget(), () => {}, signal)
		await runtime.collections(signal)
		const result = await runtime.events(captured.calendarCollections[0].resourceId, signal) as { events: Record<string, unknown>[] }
		expect(captured.calendarEvents[0].filename).toBeTruthy()
		expect(result.events[0]).toHaveProperty('filename', captured.calendarEvents[0].filename)
		expect(result.events[0].revision).toMatch(/^".*"$/)
	})

	it.each(names)('composes %s with exact native-read revision shapes', async name => {
		const api = native()
		const kind = name.startsWith('nc_calendar_') ? 'calendar' : name.startsWith('nc_tasks_') ? 'tasks' : 'contacts'
		const item = captured.marker[kind], operation = name.split('_').at(-1)!
		let args: Record<string, unknown> = {}
		if (name === 'nc_calendar_read') args = { resource_id: captured.calendarCollections[0].resourceId }
		else if (name === 'nc_tasks_read') args = { calendarId: item.collection }
		else if (name === 'nc_contacts_find') args = { query: 'Taylor' }
		else if (name === 'nc_contacts_read') args = { bookId: item.collection, filename: item.filename }
		else if (operation === 'create') args = kind === 'contacts'
			? { bookId: item.collection, filename: 'new-probe.vcf', fn: 'Synthetic Probe' }
			: { calendarId: item.collection, filename: 'new-probe.ics', summary: 'Synthetic Probe',
				...(kind === 'calendar' ? { startUtc: '2028-11-01T10:00:00Z', endUtc: '2028-11-01T11:00:00Z' } : { dueDate: '2028-11-02' }) }
		else if (operation === 'patch' || operation === 'delete') args = {
			...(kind === 'contacts' ? { bookId: item.collection } : { calendarId: item.collection }), filename: item.filename,
			expectedEtag: kind === 'calendar' ? captured.calendarEvents[0].revision : kind === 'tasks' ? captured.tasks[0].revision : captured.contact.etag,
			...(operation === 'patch' ? { changes: kind === 'contacts' ? { note: 'Updated' } : { summary: 'Updated' } } : {}),
		}
		let round = 0, toolResult: string | undefined
		const gw = gateway(request => {
			round++
			if (name === 'nc_calendar_read' && round === 1) return { toolCalls: [{ id: 'discover', name: 'nc_calendar_list', arguments: {} }] }
			if (round === (name === 'nc_calendar_read' ? 2 : 1)) return { toolCalls: [{ id: 'target', name, arguments: args }] }
			toolResult = lastToolResults(request).get('target')
			return { text: 'Diagnostic ended.' }
		})
		const host = new NextcloudAgent(identity, files(api), gw.fetch)
		cleanups.push(() => host.dispose())
		await host.signIn('owner', 'test')
		await settle(host, host.send('Run this synthetic diagnostic.', false))
		expect(host.chat.turns[0].state).toBe('completed')
		expect(toolResult).toBeDefined()
		expect(toolResult).not.toMatch(/^(?:Error:|Tool ')/)
		expect(() => JSON.parse(toolResult!)).not.toThrow()
		if (['create', 'patch', 'delete'].includes(operation)) {
			expect(kind === 'calendar' ? api.calendarCommit : kind === 'tasks' ? api.tasksCommit : api.contactsCommit).toHaveBeenCalledTimes(1)
		}

	})

	it('ordinary DAV chat and approval do not require the explicit-command principal/policy endpoint', async () => {
		const api = native(), seen: string[] = []
		let round = 0, stripped = false
		const gw = gateway(() => ++round === 1 ? { toolCalls: [{ id: 'create', name: 'nc_calendar_create', arguments: {
			calendarId: captured.marker.calendar.collection, filename: 'normal-chat.ics', summary: 'Synthetic Probe', startUtc: '2028-11-01T10:00:00Z', endUtc: '2028-11-01T11:00:00Z',
		} }] } : { text: 'Created.' })
		const fetcher: typeof gw.fetch = async (input, init) => {
			seen.push(String(input))
			const response = await gw.fetch(input, init)
			if (response.headers.get('content-type')?.includes('application/json')) {
				const value = await response.clone().json()
				if (value.object === 'agent.profile') { delete value.principal; stripped = true; return new Response(JSON.stringify(value), { status: response.status, headers: response.headers }) }
			}
			return response
		}
		const host = new NextcloudAgent(identity, files(api), fetcher)
		cleanups.push(() => host.dispose())
		await host.signIn('owner', 'test')
		await settle(host, host.send('Create a synthetic event.', false))
		expect(stripped).toBe(true)
		expect(host.agent.profile.principal).toBeUndefined()
		expect(host.chat.turns[0].state).toBe('completed')
		expect(api.calendarApprove).toHaveBeenCalledTimes(1)
		expect(api.calendarCommit).toHaveBeenCalledTimes(1)
		expect(seen.some(url => url.includes('/tools/check'))).toBe(false)
	})

	it('a read then verified edit rebases only its own revision and completes the chat follow-up', async () => {
		const api = native(), { coordinator } = workspace('files')
		vi.mocked(api.calendarCommit).mockImplementation(async () => {
			vi.mocked(api.calendarEvents).mockResolvedValue([{ ...captured.calendarEvents[0], revision: 'new-revision', content: captured.calendarEvents[0].content.replace('Synthetic Taylor review', 'Updated') }])
			return { status: 'saved', outcome: 'verified', etag: 'new-revision', target: 'synthetic' }
		})
		let round = 0
		const gw = gateway(() => {
			if (++round === 1) return { toolCalls: [{ id: 'discover', name: 'nc_calendar_list', arguments: {} }] }
			if (round === 2) return { toolCalls: [{ id: 'read', name: 'nc_calendar_read', arguments: { resource_id: captured.calendarCollections[0].resourceId } }] }
			if (round === 3) return { toolCalls: [{ id: 'edit', name: 'nc_calendar_patch', arguments: { calendarId: captured.marker.calendar.collection,
				filename: captured.marker.calendar.filename, expectedEtag: captured.calendarEvents[0].revision.replace(/^"|"$/g, ''), changes: { summary: 'Updated' } } }] }
			return { text: 'Verified save completed.' }
		})
		const host = new NextcloudAgent(identity, files(api), gw.fetch, coordinator)
		cleanups.push(() => host.dispose())
		await host.signIn('owner', 'test')
		await settle(host, host.send('Read then edit this synthetic event.', false))
		expect(api.calendarCommit).toHaveBeenCalledTimes(1)
		expect(coordinator.state.latest?.outcome).toBe('committed')
		expect(host.chat.turns[0].state).toBe('completed')
		expect(round).toBe(4)
	})

	it.each(['deleted', 'permission-lost', 'audience-changed'])('a verified %s effect finishes locally without replaying an unauthorized source', async condition => {
		const api = native()
		vi.mocked(api.calendarCommit).mockImplementation(async () => {
			if (condition === 'deleted' || condition === 'permission-lost') vi.mocked(api.calendarEvents).mockResolvedValue([])
			else vi.mocked(api.calendarEvents).mockResolvedValue([{ ...captured.calendarEvents[0], revision: 'new-revision', audience: 'public' }])
			return { status: condition === 'deleted' ? 'deleted' : 'saved', outcome: 'verified', etag: 'new-revision', target: 'synthetic' }
		})
		let round = 0
		const gw = gateway(() => {
			if (++round === 1) return { toolCalls: [{ id: 'discover', name: 'nc_calendar_list', arguments: {} }] }
			if (round === 2) return { toolCalls: [{ id: 'read', name: 'nc_calendar_read', arguments: { resource_id: captured.calendarCollections[0].resourceId } }] }
			return { toolCalls: [{ id: 'write', name: condition === 'deleted' ? 'nc_calendar_delete' : 'nc_calendar_patch', arguments: {
				calendarId: captured.marker.calendar.collection, filename: captured.marker.calendar.filename, expectedEtag: captured.calendarEvents[0].revision,
				...(condition === 'deleted' ? {} : { changes: { summary: 'Updated' } }),
			} }] }
		})
		const host = new NextcloudAgent(identity, files(api), gw.fetch)
		cleanups.push(() => host.dispose())
		await host.signIn('owner', 'test')
		await settle(host, host.send('Read then change this synthetic event.', false))
		expect(api.calendarCommit).toHaveBeenCalledTimes(1)
		expect(host.chat.turns[0].state).toBe('completed')
		expect(round).toBe(3) // No model/PII/provenance transport after the loss of replay authority.
		await expect(host.send('Continue using the earlier source.', false)).rejects.toThrow()
		expect(round).toBe(3)
		expect(api.calendarCommit).toHaveBeenCalledTimes(1)
	})
})
