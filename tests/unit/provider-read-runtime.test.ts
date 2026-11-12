import { describe, expect, it, vi } from 'vitest'
import { FakeGateway } from '../helpers/gateway071.ts'
import { ApprovalPart } from '@fadenstack/ui'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { ProviderReadRuntime, nativeCalendarReadTools } from '../../src/providers/readRuntime.ts'
import { nativeProvidersApi, type CalendarCollection, type CalendarEvent, type NativeProvidersApi } from '../../src/providers/client.ts'
import type { ProviderCapability } from '../../src/providers/registry.ts'
import type { FileApi, Identity } from '../../src/integrations/nextcloud/types.ts'
import type { PublishedTool, ScopePolicy } from '../../src/platform/scope.ts'
import { TurnBudget } from '../../src/platform/limits.ts'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => `${location.origin}${path}` }))
vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'test-csrf' }))
vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, text: string) => text }))

const published: PublishedTool[] = ['nc_calendar_list', 'nc_calendar_read'].map(name => ({ name, risk: 'read' }))
const policy: ScopePolicy = {
	version: 'policy-1',
	gatewayOrigin: 'https://faden.test',
	sources: [{ kind: 'user-files' }],
	destinations: [],
	tools: ['nc_calendar_list', 'nc_calendar_read'],
	sourceClasses: ['standard'],
	downstreamPolicy: 'unverified',
	destinationAudiences: [],
	movements: [],
	developmentLoopback: true,
}
const capability: ProviderCapability = {
	provider: 'calendar',
	apiVersion: 'caldav-v1',
	policyVersion: 'native-policy-1',
	enabled: true,
	tools: ['nc_calendar_list', 'nc_calendar_read'],
	conflict: 'read-only',
	history: 'not-applicable',
	refresh: 'manual',
	canRead: true,
	canWrite: false,
}
const collection: CalendarCollection = {
	provider: 'calendar',
	resourceId: 'nextcloud:calendar:dGVhbQ',
	collectionId: 'team',
	revision: 'calendar-r1',
	sourceClass: 'standard',
	audience: 'unknown',
	policyVersion: 'native-policy-1',
	title: 'Team Calendar',
}
const event: CalendarEvent = {
	...collection,
	resourceId: 'nextcloud:calendar:dGVhbQBldmVudC5pY3M',
	filename: 'event.ics',
	revision: 'event-r1',
	contentProfile: 'icalendar-xml-normalized',
	content: 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Fadenstack test//EN\r\nBEGIN:VEVENT\r\nUID:test-1\r\nDTSTART:20261030T120000Z\r\nDTEND:20261030T123000Z\r\nSUMMARY:Synthetic planning\r\nX-KEEP:preserved\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n',
}
const signal = () => new AbortController().signal

function api(): NativeProvidersApi {
	return {
		capabilities: vi.fn(async () => [capability]),
		calendarCollections: vi.fn(async () => [collection]),
		calendarEvents: vi.fn(async () => [event]),
	}
}
async function ready(native = api(), options: { published?: PublishedTool[]; policy?: ScopePolicy; budget?: TurnBudget } = {}) {
	const runtime = new ProviderReadRuntime(native)
	const assertIdentity = vi.fn()
	await runtime.prepare('session-1', options.published ?? published, options.policy ?? policy, options.budget ?? new TurnBudget(), assertIdentity, signal())
	return { runtime, native, assertIdentity }
}

describe('Native provider read runtime', () => {
	it('offers only implemented, native-enabled and published operations', async () => {
		const { runtime } = await ready(api(), { published: published.slice(0, 1) })
		expect(runtime.offeredTools).toEqual(['nc_calendar_list'])
		expect(() => runtime.begin('nc_calendar_read')).toThrow('unavailable')
		const disabled = api()
		vi.mocked(disabled.capabilities).mockResolvedValue([])
		expect((await ready(disabled)).runtime.offeredTools).toEqual([])
	})

	it('requires a known native collection and returns parsed bounded event properties', async () => {
		const { runtime, native } = await ready()
		await expect(runtime.events(collection.resourceId, signal())).rejects.toThrow('native discovery')
		expect(native.calendarEvents).not.toHaveBeenCalled()
		await runtime.collections(signal())
		runtime.begin('nc_calendar_read')
		const result = await runtime.events(collection.resourceId, signal()) as { events: { properties: Record<string, string> }[] }
		expect(result.events[0].properties.SUMMARY).toBe('Synthetic planning')
		expect(result.events[0].properties['X-KEEP']).toBe('preserved')
		expect(native.calendarEvents).toHaveBeenCalledWith('team', expect.any(AbortSignal))
	})

	it('intersects native capabilities with the administrator tool grant', async () => {
		const { runtime } = await ready(api(), { policy: { ...policy, tools: ['nc_calendar_list'] } })
		expect(runtime.offeredTools).toEqual(['nc_calendar_list'])
		expect(() => runtime.begin('nc_calendar_read')).toThrow('unavailable')
	})

	it('rejects paths, URLs and event references supplied as collections', async () => {
		const { runtime } = await ready()
		for (const reference of ['/team', 'https://cloud.test/calendar/team', 'nextcloud:calendar:Z3Vlc3NlZA']) {
			await expect(runtime.events(reference, signal())).rejects.toThrow()
		}
		await runtime.collections(signal())
		await runtime.events(collection.resourceId, signal())
		await expect(runtime.events(event.resourceId, signal())).rejects.toThrow('Choose a calendar collection')
	})

	it.each(['revision', 'audience', 'sourceClass', 'policyVersion', 'collectionId'] as const)('blocks historical replay after native %s changes', async field => {
		const { runtime, native } = await ready()
		await runtime.collections(signal())
		await runtime.events(collection.resourceId, signal())
		const changes = { revision: 'event-r2', audience: 'public', sourceClass: 'protected', policyVersion: 'new-policy', collectionId: 'other' } as const
		vi.mocked(native.calendarEvents).mockResolvedValue([{ ...event, [field]: changes[field] }])
		await expect(runtime.assertProcessing(signal())).rejects.toThrow('changed')
	})

	it('rechecks native authorization before a later turn and does not replay revoked content', async () => {
		const { runtime, native } = await ready()
		await runtime.collections(signal())
		await runtime.events(collection.resourceId, signal())
		runtime.finish('session-1')
		vi.mocked(native.calendarEvents).mockResolvedValue([])
		await expect(runtime.prepare('session-1', published, policy, new TurnBudget(), () => {}, signal())).rejects.toThrow('no longer available')
	})

	it('does not carry sources into another chat', async () => {
		const { runtime, native } = await ready()
		await runtime.collections(signal())
		runtime.finish('session-1')
		await runtime.prepare('session-2', published, policy, new TurnBudget(), () => {}, signal())
		expect(runtime.hasSources).toBe(false)
		vi.mocked(native.calendarCollections).mockClear()
		await runtime.assertProcessing(signal())
		expect(native.calendarCollections).not.toHaveBeenCalled()
	})

	it.each(['unknown', 'protected'] as const)('refuses disclosure of %s data under an unverified downstream policy', async sourceClass => {
		const native = api()
		vi.mocked(native.calendarCollections).mockResolvedValue([{ ...collection, sourceClass }])
		const { runtime } = await ready(native)
		await expect(runtime.collections(signal())).rejects.toThrow('cannot be sent')
	})

	it('shares the caller action budget rather than resetting it for provider tools', async () => {
		const budget = new TurnBudget({ calls: 2 })
		budget.begin(false)
		const { runtime } = await ready(api(), { budget })
		runtime.begin('nc_calendar_list')
		expect(() => runtime.begin('nc_calendar_list')).toThrow('action limit')
		expect(budget.usage.calls).toBe(2)
	})

	it('rejects malformed metadata, foreign collection results, and too many native results', async () => {
		const { runtime, native } = await ready()
		vi.mocked(native.calendarCollections).mockResolvedValue([{ ...collection, revision: 123 } as unknown as CalendarCollection])
		await expect(runtime.collections(signal())).rejects.toThrow('outside the current policy')
		vi.mocked(native.calendarCollections).mockResolvedValue([collection])
		await runtime.collections(signal())
		vi.mocked(native.calendarEvents).mockResolvedValue([{ ...event, collectionId: 'other' }])
		await expect(runtime.events(collection.resourceId, signal())).rejects.toThrow('Invalid native')
		vi.mocked(native.calendarEvents).mockResolvedValue(Array.from({ length: 51 }, () => event))
		await expect(runtime.events(collection.resourceId, signal())).rejects.toThrow('Narrow')
	})

	it('a late cancelled response cannot populate a subsequent turn', async () => {
		const { runtime, native } = await ready()
		let complete!: (value: CalendarCollection[]) => void
		vi.mocked(native.calendarCollections).mockImplementationOnce(() => new Promise(resolve => { complete = resolve }))
		const controller = new AbortController()
		const oldRead = runtime.collections(controller.signal)
		const rejection = expect(oldRead).rejects.toThrow()
		controller.abort()
		runtime.finish('session-1')
		await runtime.prepare('session-2', published, policy, new TurnBudget(), () => {}, signal())
		complete([collection])
		await rejection
		expect(runtime.hasSources).toBe(false)
		await expect(runtime.events(collection.resourceId, signal())).rejects.toThrow('native discovery')
	})

	it('a late capability response cannot replace a more recent turn', async () => {
		const native = api()
		let complete!: (value: ProviderCapability[]) => void
		vi.mocked(native.capabilities).mockImplementationOnce(() => new Promise(resolve => { complete = resolve }))
		const runtime = new ProviderReadRuntime(native)
		const oldPrepare = runtime.prepare('old', published, policy, new TurnBudget(), () => {}, signal())
		const rejection = expect(oldPrepare).rejects.toThrow('turn changed')
		await runtime.prepare('new', published, policy, new TurnBudget(), () => {}, signal())
		complete([capability])
		await rejection
		expect(runtime.offeredTools).toEqual(['nc_calendar_list', 'nc_calendar_read'])
	})

	it('the fixed native client never accepts model URLs, methods or credentials', async () => {
		const post = vi.fn(async (path: string) => path.endsWith('capabilities') ? { providers: [capability] } : path.endsWith('collections') ? { collections: [collection] } : { events: [event] })
		const native = nativeProvidersApi(post as Parameters<typeof nativeProvidersApi>[0])
		const abort = signal()
		await native.capabilities(abort)
		await native.calendarCollections(abort)
		await native.calendarEvents('team', abort)
		expect(post.mock.calls).toEqual([
			['/providers/capabilities', {}, abort], ['/providers/calendar/collections', {}, abort], ['/providers/calendar/events', { collectionId: 'team' }, abort],
		])
	})

	it('tool schemas reject invented arguments without native effects', async () => {
		const { runtime, native } = await ready()
		const tool = nativeCalendarReadTools(runtime)[0]
		await expect(tool.invoke({ url: 'https://example.test' }, { callId: 'bad', signal: signal() })).rejects.toThrow()
		expect(native.calendarCollections).not.toHaveBeenCalled()
	})
})

function files(native = api()): FileApi {
	return {
		identity: vi.fn(async () => identity),
		assertIdentity: vi.fn(async () => {}),
		select: vi.fn(),
		read: vi.fn(),
		pdf: vi.fn(),
		list: vi.fn(),
		propose: vi.fn(),
		approve: vi.fn(),
		commit: vi.fn(),
		scopeResource: vi.fn(),
		nativeProviders: native,
	}
}
const identity: Identity = {
	userId: 'alice',
	binding: 'native-session-1',
	enabled: true,
	serverUrl: 'https://faden.test',
	agent: 'calendar-agent',
	development: true,
	providerReadsEnabled: true,
	scopePolicy: policy,
}
function gateway(model?: ConstructorParameters<typeof FakeGateway>[0]['model']) {
	return new FakeGateway({ agents: [{ slug: 'calendar-agent', version: 1, contract: published }], users: [{ email: 'alice', password: 'test', agents: ['calendar-agent'] }], model })
}

describe('Native Calendar through the composed published SDK (mock gateway/native API)', () => {
	it.each(['disabled', 'unpublished-version', 'local-grant'] as const)('does not restore a %s provider through the generic Files scope', async mode => {
		const native = api()
		if (mode === 'disabled') vi.mocked(native.capabilities).mockResolvedValue([])
		if (mode === 'unpublished-version') vi.mocked(native.capabilities).mockResolvedValue([{ ...capability, apiVersion: 'untested-v99' }])
		const config = mode === 'local-grant' ? { ...identity, scopePolicy: { ...policy, tools: ['nc_calendar_read'] } } : identity
		let offered: string[] = []
		let round = 0
		const gw = gateway(request => {
			offered = request.tools.map(tool => tool.name)
			return ++round === 1 ? { toolCalls: [{ id: 'invented', name: 'nc_calendar_list', arguments: {} }] } : { text: 'Unavailable.' }
		})
		const host = new NextcloudAgent(config, files(native), gw.fetch)
		try {
			await host.signIn('alice', 'test')
			await host.send('List calendars.', false)
			expect(offered).not.toContain('nc_calendar_list')
			expect(native.calendarCollections).not.toHaveBeenCalled()
		} finally { await host.dispose() }
	})
	it('stays absent without both native development gates', async () => {
		for (const flags of [{ development: false }, { providerReadsEnabled: false }, { providerReadsEnabled: undefined }]) {
			const host = new NextcloudAgent({ ...identity, ...flags }, files(), gateway().fetch)
			await host.signIn('alice', 'test')
			expect(host.agent.find('nc_calendar_list')).toBeUndefined()
			await host.dispose()
		}
	})

	it('lists then reads known native resources through the real tool stages', async () => {
		let round = 0
		const gw = gateway(() => ++round === 1
			? { toolCalls: [{ id: 'list', name: 'nc_calendar_list', arguments: {} }] }
			: round === 2 ? { toolCalls: [{ id: 'read', name: 'nc_calendar_read', arguments: { resource_id: collection.resourceId } }] } : { text: 'Synthetic planning is on the calendar.' })
		const native = api()
		const host = new NextcloudAgent(identity, files(native), gw.fetch)
		await host.signIn('alice', 'test')
		await host.send('Read my allowed calendar.', false)
		expect(round).toBe(3)
		expect(host.chat.turns[0].state).toBe('completed')
		expect(native.calendarEvents).toHaveBeenCalledWith('team', expect.any(AbortSignal))
		await host.dispose()
	})

	it('prevents a model follow-up when native event access is revoked after the read', async () => {
		let round = 0
		const native = api()
		const gw = gateway(() => ++round === 1
			? { toolCalls: [{ id: 'list', name: 'nc_calendar_list', arguments: {} }] }
			: { toolCalls: [{ id: 'read', name: 'nc_calendar_read', arguments: { resource_id: collection.resourceId } }] })
		vi.mocked(native.calendarEvents).mockResolvedValueOnce([event]).mockResolvedValue([])
		const host = new NextcloudAgent(identity, files(native), gw.fetch)
		await host.signIn('alice', 'test')
		await host.send('Read my allowed calendar.', false)
		expect(round).toBe(2)
		expect(host.chat.turns[0].state).toBe('failed')
		expect(host.chat.turns[0].errorMessage).toContain('no longer available')
		await host.dispose()
	})

	it('does not disclose a provider result after the shared result budget is exhausted', async () => {
		let round = 0
		let followUp = ''
		const gw = gateway(request => {
			if (++round === 1) return { toolCalls: [{ id: 'list', name: 'nc_calendar_list', arguments: {} }] }
			followUp = JSON.stringify(request.messages)
			return { text: 'The Calendar result exceeded the limit.' }
		})
		const host = new NextcloudAgent({ ...identity, scopePolicy: { ...policy, limits: { records: 1 } } }, files(), gw.fetch)
		await host.signIn('alice', 'test')
		await host.send('List my calendars.', false)
		expect(round).toBe(2)
		expect(followUp).toContain('result limit')
		expect(followUp).not.toContain(collection.title)
		expect(followUp).not.toContain(collection.resourceId)
		await host.dispose()
	})

	it('withholds Files mutations after provider content and rejects an invented write before native proposal or approval', async () => {
		const contract: PublishedTool[] = [...published, { name: 'nc_create_text_file', risk: 'destructive' }]
		const allowedFilesPolicy: ScopePolicy = { ...policy, tools: [...policy.tools, 'nc_create_text_file'], destinations: [{ kind: 'user-files' }], destinationAudiences: ['private'], movements: [{ source: 'standard', destination: 'private' }] }
		const native = files()
		let round = 0
		const gw = new FakeGateway({ agents: [{ slug: 'calendar-agent', version: 1, contract }], users: [{ email: 'alice', password: 'test' }], model: request => {
			if (++round === 1) {
				expect(request.tools.some(tool => tool.name === 'nc_create_text_file')).toBe(true)
				return { toolCalls: [{ id: 'list', name: 'nc_calendar_list', arguments: {} }] }
			}
			if (round === 2) {
				expect(request.tools.some(tool => tool.name === 'nc_create_text_file')).toBe(false)
				return { toolCalls: [{ id: 'unexpected-write', name: 'nc_create_text_file', arguments: { filename: 'should-not-exist.md', content: 'From calendar' } }] }
			}
			return { text: 'Provider-to-file writes are unavailable.' }
		} })
		const host = new NextcloudAgent({ ...identity, scopePolicy: allowedFilesPolicy }, native, gw.fetch)
		await host.signIn('alice', 'test')
		await host.send('List calendars and write a file.', false)
		expect(round).toBe(2)
		expect(host.chat.turns[0].state).toBe('failed')
		expect(host.chat.turns[0].errorMessage).toContain('Writing from provider sources is not enabled')
		expect(native.propose).not.toHaveBeenCalled()
		expect(native.approve).not.toHaveBeenCalled()
		expect(native.commit).not.toHaveBeenCalled()
		expect(host.chat.turns.flatMap(turn => [...turn.parts]).some(part => part instanceof ApprovalPart)).toBe(false)
		await host.dispose()
	})
})
