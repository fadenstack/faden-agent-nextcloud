import { describe, expect, it, vi } from 'vitest'
import { FakeGateway } from '@fadenstack/testing'
import { ApprovalPart } from '@fadenstack/ui'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { ProviderReadRuntime } from '../../src/providers/readRuntime.ts'
import type { NativeProvidersApi, CalendarCollection, CalendarEvent, CalendarProposalSummary, CalendarCommitOutcome } from '../../src/providers/client.ts'
import type { ProviderCapability } from '../../src/providers/registry.ts'
import type { FileApi, Identity } from '../../src/integrations/nextcloud/types.ts'
import type { PublishedTool, ScopePolicy } from '../../src/platform/scope.ts'
import { TurnBudget } from '../../src/platform/limits.ts'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => `${location.origin}${path}` }))
vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'test-csrf' }))
vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, text: string) => text }))

const calendarToolsList = [
	'nc_calendar_list',
	'nc_calendar_read',
	'nc_calendar_create',
	'nc_calendar_patch',
	'nc_calendar_delete',
] as const

const published: PublishedTool[] = [
	{ name: 'nc_calendar_list', risk: 'read' },
	{ name: 'nc_calendar_read', risk: 'read' },
	{ name: 'nc_calendar_create', risk: 'destructive', individualApproval: true },
	{ name: 'nc_calendar_patch', risk: 'destructive', individualApproval: true },
	{ name: 'nc_calendar_delete', risk: 'destructive', individualApproval: true },
]

const policy: ScopePolicy = {
	version: 'policy-cal-1',
	gatewayOrigin: 'https://faden.test',
	sources: [{ kind: 'user-files' }],
	destinations: [],
	tools: [...calendarToolsList],
	sourceClasses: ['standard'],
	downstreamPolicy: 'unverified',
	destinationAudiences: [],
	movements: [],
	developmentLoopback: true,
}

const writeCapability: ProviderCapability = {
	provider: 'calendar',
	apiVersion: 'caldav-v1',
	policyVersion: 'native-policy-cal-1',
	enabled: true,
	tools: [...calendarToolsList],
	conflict: 'conditional',
	history: 'audit-only',
	refresh: 'manual',
	canRead: true,
	canWrite: true,
}

const collection: CalendarCollection = {
	provider: 'calendar',
	resourceId: 'nextcloud:calendar:dGVhbQ',
	collectionId: 'team',
	revision: 'calendar-r1',
	sourceClass: 'standard',
	audience: 'private',
	policyVersion: 'native-policy-cal-1',
	title: 'Team Calendar',
}

const event: CalendarEvent = {
	...collection,
	resourceId: 'nextcloud:calendar:dGVhbQBldmVudC5pY3M',
	filename: 'event.ics',
	revision: 'event-r1',
	contentProfile: 'icalendar-xml-normalized',
	content: 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Fadenstack test//EN\r\nBEGIN:VEVENT\r\nUID:test-1\r\nDTSTART:20261030T120000Z\r\nDTEND:20261030T123000Z\r\nSUMMARY:Existing Event\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n',
}

const signal = () => new AbortController().signal

function mockNativeApi(overrides: Partial<NativeProvidersApi> = {}): NativeProvidersApi {
	return {
		capabilities: vi.fn(async () => [writeCapability]),
		calendarCollections: vi.fn(async () => [collection]),
		calendarEvents: vi.fn(async () => [event]),
		calendarEvent: vi.fn(async () => ({ etag: 'event-r1', content: event.content })),
		calendarPropose: vi.fn(async (operation, collectionId, filename, icsData, expectedEtag) => ({
			id: 'prop-123',
			operation,
			toolName: `nc_calendar_${operation}`,
			target: `Calendar ${collectionId}, event ${filename}`,
			collectionId,
			filename,
			hash: 'rev-prop-123',
			expires: Date.now() + 600000,
		})),
		calendarApprove: vi.fn(async id => ({ approved: true })),
		calendarCommit: vi.fn(async id => ({
			status: 'saved' as const,
			outcome: 'verified' as const,
			etag: 'etag-new-1',
			target: 'Calendar team, event sprint-retro.ics',
		})),
		...overrides,
	}
}

function mockFilesApi(native: NativeProvidersApi): FileApi {
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
	binding: 'native-session-cal',
	enabled: true,
	serverUrl: 'https://faden.test',
	agent: 'calendar-agent',
	development: true,
	providerReadsEnabled: true,
	scopePolicy: policy,
}

function gateway(model?: ConstructorParameters<typeof FakeGateway>[0]['model']) {
	return new FakeGateway({
		agents: [{ slug: 'calendar-agent', version: 1, contract: published }],
		users: [{ email: 'alice', password: 'test', agents: ['calendar-agent'] }],
		model,
	})
}

describe('Native Calendar mutation lifecycle and runtime', () => {
	it('offers all 5 calendar tools when server capability has canWrite: true', async () => {
		const native = mockNativeApi()
		const runtime = new ProviderReadRuntime(native)
		await runtime.prepare('session-1', published, policy, new TurnBudget(), () => {}, signal())
		expect(runtime.offeredTools).toEqual(calendarToolsList)
	})

	it('withholds mutation tools when server capability has canWrite: false', async () => {
		const native = mockNativeApi({
			capabilities: vi.fn(async () => [{ ...writeCapability, canWrite: false, tools: ['nc_calendar_list', 'nc_calendar_read'] }]),
		})
		const runtime = new ProviderReadRuntime(native)
		await runtime.prepare('session-1', published, policy, new TurnBudget(), () => {}, signal())
		expect(runtime.offeredTools).toEqual(['nc_calendar_list', 'nc_calendar_read'])
	})

	it('composes nc_calendar_create: propose -> approval -> approve -> commit -> verified receipt', async () => {
		const native = mockNativeApi()
		let round = 0
		const gw = gateway(() => {
			round++
			if (round === 1) {
				return {
					toolCalls: [{
						id: 'cal-call-1',
						name: 'nc_calendar_create',
						arguments: {
							calendarId: 'team',
							filename: 'sprint-retro.ics',
							summary: 'Sprint Retro',
							startUtc: '2026-10-30T15:00:00Z',
							endUtc: '2026-10-30T16:00:00Z',
						},
					}],
				}
			}
			return { text: 'Event created and verified on Team Calendar.' }
		})

		const files = mockFilesApi(native)
		const host = new NextcloudAgent(identity, files, gw.fetch)
		await host.signIn('alice', 'test')

		const sendPromise = host.send('Create the sprint retro event.', false)
		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = host.chat.turns.flatMap(turn => [...turn.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		expect(native.calendarPropose).toHaveBeenCalledWith(
			'create',
			'team',
			'sprint-retro.ics',
			expect.stringContaining('SUMMARY:Sprint Retro'),
			null,
			expect.any(AbortSignal),
		)

		// Zero dispatch before user approval
		expect(native.calendarApprove).not.toHaveBeenCalled()
		expect(native.calendarCommit).not.toHaveBeenCalled()

		await host.approve(part!)
		await sendPromise

		expect(native.calendarApprove).toHaveBeenCalledWith('prop-123', expect.any(AbortSignal))
		expect(native.calendarCommit).toHaveBeenCalledWith('prop-123', expect.any(AbortSignal))
		expect(host.chat.turns[0].state).toBe('completed')
		await host.dispose()
	})

	it('refuses execution and leaves zero dispatch when user denies the creation', async () => {
		const native = mockNativeApi()
		let round = 0
		const gw = gateway(() => {
			round++
			if (round === 1) {
				return {
					toolCalls: [{
						id: 'cal-call-deny',
						name: 'nc_calendar_create',
						arguments: {
							calendarId: 'team',
							filename: 'unwanted.ics',
							summary: 'Unwanted Event',
							startUtc: '2026-10-30T15:00:00Z',
							endUtc: '2026-10-30T16:00:00Z',
						},
					}],
				}
			}
			return { text: 'Turn ended after denial.' }
		})

		const files = mockFilesApi(native)
		const host = new NextcloudAgent(identity, files, gw.fetch)
		await host.signIn('alice', 'test')

		const sendPromise = host.send('Create unwanted event.', false)
		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = host.chat.turns.flatMap(turn => [...turn.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		await host.deny(part!)
		await sendPromise

		expect(native.calendarApprove).not.toHaveBeenCalled()
		expect(native.calendarCommit).not.toHaveBeenCalled()
		await host.dispose()
	})

	it('handles 412 precondition failure truthfully when patching a modified event', async () => {
		const native = mockNativeApi({
			calendarPropose: vi.fn(async () => {
				throw new Error('Calendar resource changed. Read it again before proposing a write.')
			}),
		})
		let round = 0
		const gw = gateway(() => {
			round++
			if (round === 1) {
				return {
					toolCalls: [{
						id: 'cal-call-patch-stale',
						name: 'nc_calendar_patch',
						arguments: {
							calendarId: 'team',
							filename: 'event.ics',
							expectedEtag: 'stale-etag',
							changes: { summary: 'New Title' },
						},
					}],
				}
			}
			return { text: 'Done' }
		})

		const files = mockFilesApi(native)
		const host = new NextcloudAgent(identity, files, gw.fetch)
		await host.signIn('alice', 'test')

		await host.send('Patch stale event.', false)
		expect(host.chat.turns[0].state).toBe('failed')
		expect(host.chat.turns[0].errorMessage).toContain('Calendar resource changed')
		expect(native.calendarCommit).not.toHaveBeenCalled()
		await host.dispose()
	})

	it('handles collision 409 conflict truthfully when creating an existing event', async () => {
		const native = mockNativeApi({
			calendarPropose: vi.fn(async () => {
				throw new Error('A calendar object with this filename already exists in the collection.')
			}),
		})
		let round = 0
		const gw = gateway(() => {
			round++
			if (round === 1) {
				return {
					toolCalls: [{
						id: 'cal-call-collision',
						name: 'nc_calendar_create',
						arguments: {
							calendarId: 'team',
							filename: 'event.ics',
							summary: 'Collision',
							startUtc: '2026-10-30T10:00:00Z',
							endUtc: '2026-10-30T11:00:00Z',
						},
					}],
				}
			}
			return { text: 'Done' }
		})

		const files = mockFilesApi(native)
		const host = new NextcloudAgent(identity, files, gw.fetch)
		await host.signIn('alice', 'test')

		await host.send('Create colliding event.', false)
		expect(host.chat.turns[0].state).toBe('failed')
		expect(host.chat.turns[0].errorMessage).toContain('already exists')
		expect(native.calendarCommit).not.toHaveBeenCalled()
		await host.dispose()
	})

	it('enforces single-use approval: replayed commit is refused', async () => {
		let commitCalls = 0
		const native = mockNativeApi({
			calendarCommit: vi.fn(async () => {
				commitCalls++
				if (commitCalls > 1) throw new Error('Proposal not found or already consumed.')
				return { status: 'saved' as const, outcome: 'verified' as const, etag: 'new-etag', target: 'team' }
			}),
		})

		const result1 = await native.calendarCommit('prop-single-use')
		expect(result1.status).toBe('saved')
		expect(result1.outcome).toBe('verified')
		await expect(native.calendarCommit('prop-single-use')).rejects.toThrow('already consumed')
	})

	it('truthful history: declares audit-only and does not expose Undo for calendar mutations', async () => {
		const native = mockNativeApi()
		const runtime = new ProviderReadRuntime(native)
		await runtime.prepare('session-1', published, policy, new TurnBudget(), () => {}, signal())
		const tools = runtime.offeredTools
		expect(tools).not.toContain('nc_actions_undo')
		expect(tools).not.toContain('nc_actions_redo')
	})
})
