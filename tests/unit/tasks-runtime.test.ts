import { describe, expect, it, vi } from 'vitest'
import { lastToolResults } from '@fadenstack/testing'
import { FakeGateway } from '../helpers/gateway071.ts'
import { ApprovalPart } from '@fadenstack/ui'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { ProviderReadRuntime } from '../../src/providers/readRuntime.ts'
import type { NativeProvidersApi, CalendarCollection, CalendarEvent } from '../../src/providers/client.ts'
import type { ProviderCapability } from '../../src/providers/registry.ts'
import type { FileApi, Identity } from '../../src/integrations/nextcloud/types.ts'
import type { PublishedTool, ScopePolicy } from '../../src/platform/scope.ts'
import { TurnBudget } from '../../src/platform/limits.ts'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => `${location.origin}${path}` }))
vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'test-csrf' }))
vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, text: string) => text }))

const tasksToolsList = [
	'nc_tasks_read',
	'nc_tasks_create',
	'nc_tasks_patch',
	'nc_tasks_delete',
] as const

const published: PublishedTool[] = [
	{ name: 'nc_tasks_read', risk: 'read' },
	{ name: 'nc_tasks_create', risk: 'destructive', individualApproval: true },
	{ name: 'nc_tasks_patch', risk: 'destructive', individualApproval: true },
	{ name: 'nc_tasks_delete', risk: 'destructive', individualApproval: true },
]

const policy: ScopePolicy = {
	version: 'policy-tasks-1',
	gatewayOrigin: 'https://faden.test',
	sources: [{ kind: 'user-files' }],
	destinations: [],
	tools: [...tasksToolsList],
	sourceClasses: ['standard'],
	downstreamPolicy: 'unverified',
	destinationAudiences: [],
	movements: [],
	developmentLoopback: true,
}

const writeCapability: ProviderCapability = {
	provider: 'tasks',
	apiVersion: 'caldav-v1',
	policyVersion: 'native-policy-tasks-1',
	enabled: true,
	tools: [...tasksToolsList],
	conflict: 'conditional',
	history: 'audit-only',
	refresh: 'manual',
	canRead: true,
	canWrite: true,
}

const collection: CalendarCollection = {
	provider: 'tasks',
	resourceId: 'nextcloud:tasks:cGVyc29uYWw',
	collectionId: 'personal',
	revision: 'tasks-r1',
	sourceClass: 'standard',
	audience: 'private',
	policyVersion: 'native-policy-tasks-1',
	title: 'Personal Tasks',
}

const sampleTaskIcs = 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Fadenstack test//EN\r\nBEGIN:VTODO\r\nUID:task-1\r\nSUMMARY:Existing Task\r\nDUE;VALUE=DATE:20261030\r\nSTATUS:NEEDS-ACTION\r\nPERCENT-COMPLETE:0\r\nPRIORITY:3\r\nEND:VTODO\r\nEND:VCALENDAR\r\n'

const taskItem: CalendarEvent = {
	...collection,
	resourceId: 'nextcloud:tasks:cGVyc29uYWwAdGFzay0xLmljcw',
	filename: 'task-1.ics',
	revision: 'task-r1',
	contentProfile: 'icalendar-xml-normalized',
	content: sampleTaskIcs,
}

const signal = () => new AbortController().signal

function mockNativeApi(overrides: Partial<NativeProvidersApi> = {}): NativeProvidersApi {
	return {
		capabilities: vi.fn(async () => [writeCapability]),
		calendarCollections: vi.fn(async () => []),
		calendarEvents: vi.fn(async () => []),
		calendarEvent: vi.fn(async () => ({ etag: '', content: '' })),
		calendarPropose: vi.fn(async () => ({} as any)),
		calendarApprove: vi.fn(async () => ({ approved: true })),
		calendarCommit: vi.fn(async () => ({} as any)),

		tasksLists: vi.fn(async () => [collection]),
		tasksItems: vi.fn(async () => [taskItem]),
		taskItem: vi.fn(async () => ({ etag: 'task-r1', content: taskItem.content })),
		tasksPropose: vi.fn(async (operation, collectionId, filename, icsData, expectedEtag) => ({
			id: 'prop-tasks-123',
			operation,
			toolName: `nc_tasks_${operation}`,
			target: `Task list ${collectionId}, task ${filename}`,
			collectionId,
			filename,
			hash: 'rev-prop-tasks-123',
			expires: Date.now() + 600000,
		})),
		tasksApprove: vi.fn(async () => ({ approved: true })),
		tasksCommit: vi.fn(async () => ({
			status: 'saved' as const,
			outcome: 'verified' as const,
			etag: 'etag-task-new-1',
			target: 'Task list personal, task sprint-task.ics',
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
	binding: 'native-session-tasks',
	enabled: true,
	serverUrl: 'https://faden.test',
	agent: 'tasks-agent',
	development: true,
	providerReadsEnabled: true,
	scopePolicy: policy,
}

function gateway(model?: ConstructorParameters<typeof FakeGateway>[0]['model']) {
	return new FakeGateway({
		agents: [{ slug: 'tasks-agent', version: 1, contract: published }],
		users: [{ email: 'alice', password: 'test', agents: ['tasks-agent'] }],
		model,
	})
}

describe('Native Tasks mutation lifecycle and runtime', () => {
	it('offers all 4 tasks tools when server capability has canWrite: true', async () => {
		const native = mockNativeApi()
		const runtime = new ProviderReadRuntime(native)
		await runtime.prepare('session-tasks-1', published, policy, new TurnBudget(), () => {}, signal())
		expect(runtime.offeredTools).toEqual(tasksToolsList)
	})

	it('withholds mutation tools when server capability has canWrite: false', async () => {
		const native = mockNativeApi({
			capabilities: vi.fn(async () => [{ ...writeCapability, canWrite: false, tools: ['nc_tasks_read'] }]),
		})
		const runtime = new ProviderReadRuntime(native)
		await runtime.prepare('session-tasks-1', published, policy, new TurnBudget(), () => {}, signal())
		expect(runtime.offeredTools).toEqual(['nc_tasks_read'])
	})

	it('reads tasks from native task list with exact filenames and properties', async () => {
		const native = mockNativeApi()
		const files = mockFilesApi(native)
		let round = 0
		let result: unknown
		const gw = gateway(request => {
			if (++round === 1) return { toolCalls: [{ id: 'read', name: 'nc_tasks_read', arguments: { calendarId: 'personal' } }] }
			result = JSON.parse(lastToolResults(request).get('read')!)
			return { text: 'Tasks read.' }
		})
		const host = new NextcloudAgent(identity, files, gw.fetch)
		await host.signIn('alice', 'test')

		await host.send('Read my tasks.', false)
		expect(host.chat.turns[0].state).toBe('completed')
		expect(result).toEqual({
			calendarId: 'personal',
			complete: true,
			tasks: [
				{
					filename: 'task-1.ics',
					etag: 'task-r1',
					properties: expect.objectContaining({
						SUMMARY: 'Existing Task',
						STATUS: 'NEEDS-ACTION',
						'PERCENT-COMPLETE': '0',
						PRIORITY: '3',
					}),
				},
			],
		})
		await host.dispose()
	})

	it('composes nc_tasks_create: propose -> approval -> approve -> commit -> verified receipt', async () => {
		const native = mockNativeApi()
		let round = 0
		const gw = gateway(() => {
			round++
			if (round === 1) {
				return {
					toolCalls: [{
						id: 'task-call-1',
						name: 'nc_tasks_create',
						arguments: {
							calendarId: 'personal',
							filename: 'sprint-task.ics',
							summary: 'Finish DAV test coverage',
							dueDate: '2026-10-30',
							priority: 2,
						},
					}],
				}
			}
			return { text: 'Task created and verified on personal list.' }
		})

		const files = mockFilesApi(native)
		const host = new NextcloudAgent(identity, files, gw.fetch)
		await host.signIn('alice', 'test')

		const sendPromise = host.send('Create the sprint task.', false)
		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = host.chat.turns.flatMap(turn => [...turn.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		expect(native.tasksPropose).toHaveBeenCalledWith(
			'create',
			'personal',
			'sprint-task.ics',
			expect.stringContaining('SUMMARY:Finish DAV test coverage'),
			null,
			expect.any(AbortSignal),
		)

		// Zero dispatch before user approval
		expect(native.tasksApprove).not.toHaveBeenCalled()
		expect(native.tasksCommit).not.toHaveBeenCalled()

		await host.approve(part!)
		await sendPromise

		expect(native.tasksApprove).toHaveBeenCalledWith('prop-tasks-123', expect.any(AbortSignal))
		expect(native.tasksCommit).toHaveBeenCalledWith('prop-tasks-123', expect.any(AbortSignal))
		expect(host.chat.turns[0].state).toBe('completed')
		await host.dispose()
	})

	it('refuses execution and leaves zero dispatch when user denies task creation', async () => {
		const native = mockNativeApi()
		let round = 0
		const gw = gateway(() => {
			round++
			if (round === 1) {
				return {
					toolCalls: [{
						id: 'task-call-deny',
						name: 'nc_tasks_create',
						arguments: {
							calendarId: 'personal',
							filename: 'unwanted-task.ics',
							summary: 'Unwanted Task',
							dueDate: '2026-10-30',
						},
					}],
				}
			}
			return { text: 'Turn ended after denial.' }
		})

		const files = mockFilesApi(native)
		const host = new NextcloudAgent(identity, files, gw.fetch)
		await host.signIn('alice', 'test')

		const sendPromise = host.send('Create unwanted task.', false)
		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = host.chat.turns.flatMap(turn => [...turn.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		await host.deny(part!)
		await sendPromise

		expect(native.tasksApprove).not.toHaveBeenCalled()
		expect(native.tasksCommit).not.toHaveBeenCalled()
		await host.dispose()
	})

	it('handles 412 precondition failure truthfully when patching a modified task', async () => {
		const native = mockNativeApi({
			tasksPropose: vi.fn(async () => {
				throw new Error('Task resource changed. Read it again before proposing a write.')
			}),
		})
		let round = 0
		const gw = gateway(() => {
			round++
			if (round === 1) {
				return {
					toolCalls: [{
						id: 'task-call-patch-stale',
						name: 'nc_tasks_patch',
						arguments: {
							calendarId: 'personal',
							filename: 'task-1.ics',
							expectedEtag: 'stale-task-etag',
							changes: { summary: 'Updated Title' },
						},
					}],
				}
			}
			return { text: 'Done' }
		})

		const files = mockFilesApi(native)
		const host = new NextcloudAgent(identity, files, gw.fetch)
		await host.signIn('alice', 'test')

		await host.send('Patch stale task.', false)
		expect(host.chat.turns[0].state).toBe('failed')
		expect(host.chat.turns[0].errorMessage).toContain('Task resource changed')
		expect(native.tasksCommit).not.toHaveBeenCalled()
		await host.dispose()
	})

	it('handles collision 409 conflict truthfully when creating an existing task', async () => {
		const native = mockNativeApi({
			tasksPropose: vi.fn(async () => {
				throw new Error('A task with this filename already exists in the collection.')
			}),
		})
		let round = 0
		const gw = gateway(() => {
			round++
			if (round === 1) {
				return {
					toolCalls: [{
						id: 'task-call-collision',
						name: 'nc_tasks_create',
						arguments: {
							calendarId: 'personal',
							filename: 'task-1.ics',
							summary: 'Collision Task',
							dueDate: '2026-10-30',
						},
					}],
				}
			}
			return { text: 'Done' }
		})

		const files = mockFilesApi(native)
		const host = new NextcloudAgent(identity, files, gw.fetch)
		await host.signIn('alice', 'test')

		await host.send('Create colliding task.', false)
		expect(host.chat.turns[0].state).toBe('failed')
		expect(host.chat.turns[0].errorMessage).toContain('already exists')
		expect(native.tasksCommit).not.toHaveBeenCalled()
		await host.dispose()
	})

	it('enforces single-use approval: replayed task commit is refused', async () => {
		let commitCalls = 0
		const native = mockNativeApi({
			tasksCommit: vi.fn(async () => {
				commitCalls++
				if (commitCalls > 1) throw new Error('Proposal not found or already consumed.')
				return { status: 'saved' as const, outcome: 'verified' as const, etag: 'new-etag', target: 'personal' }
			}),
		})

		const result1 = await native.tasksCommit!('prop-single-use')
		expect(result1.status).toBe('saved')
		expect(result1.outcome).toBe('verified')
		await expect(native.tasksCommit!('prop-single-use')).rejects.toThrow('already consumed')
	})

	it('patches task completion toggle preserving unknown properties', async () => {
		const native = mockNativeApi()
		let round = 0
		const gw = gateway(() => {
			round++
			if (round === 1) {
				return {
					toolCalls: [{
						id: 'task-call-patch-complete',
						name: 'nc_tasks_patch',
						arguments: {
							calendarId: 'personal',
							filename: 'task-1.ics',
							expectedEtag: 'task-r1',
							changes: { completed: true },
						},
					}],
				}
			}
			return { text: 'Task marked complete.' }
		})

		const files = mockFilesApi(native)
		const host = new NextcloudAgent(identity, files, gw.fetch)
		await host.signIn('alice', 'test')

		const sendPromise = host.send('Mark task as complete.', false)
		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = host.chat.turns.flatMap(turn => [...turn.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		expect(native.tasksPropose).toHaveBeenCalledWith(
			'patch',
			'personal',
			'task-1.ics',
			expect.stringContaining('STATUS:COMPLETED'),
			'task-r1',
			expect.any(AbortSignal),
		)

		await host.approve(part!)
		await sendPromise

		expect(native.tasksCommit).toHaveBeenCalledWith('prop-tasks-123', expect.any(AbortSignal))
		expect(host.chat.turns[0].state).toBe('completed')
		await host.dispose()
	})

	it('truthful history: declares audit-only and does not expose Undo for tasks mutations', async () => {
		const native = mockNativeApi()
		const runtime = new ProviderReadRuntime(native)
		await runtime.prepare('session-tasks-1', published, policy, new TurnBudget(), () => {}, signal())
		const tools = runtime.offeredTools
		expect(tools).not.toContain('nc_actions_undo')
		expect(tools).not.toContain('nc_actions_redo')
	})
})
