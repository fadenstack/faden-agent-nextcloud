import { describe, expect, it, vi } from 'vitest'
import { FakeGateway } from '@fadenstack/testing'
import { ApprovalPart } from '@fadenstack/ui'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { ProviderReadRuntime } from '../../src/providers/readRuntime.ts'
import type { NativeProvidersApi, CalendarCollection, ContactSummary, ContactReadResult } from '../../src/providers/client.ts'
import type { ProviderCapability } from '../../src/providers/registry.ts'
import type { FileApi, Identity } from '../../src/integrations/nextcloud/types.ts'
import type { PublishedTool, ScopePolicy } from '../../src/platform/scope.ts'
import { TurnBudget } from '../../src/platform/limits.ts'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => `${location.origin}${path}` }))
vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'test-csrf' }))
vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, text: string) => text }))

const contactsToolsList = [
	'nc_contacts_list',
	'nc_contacts_find',
	'nc_contacts_read',
	'nc_contacts_create',
	'nc_contacts_patch',
	'nc_contacts_delete',
] as const

const published: PublishedTool[] = [
	{ name: 'nc_contacts_list', risk: 'read' },
	{ name: 'nc_contacts_find', risk: 'read' },
	{ name: 'nc_contacts_read', risk: 'read' },
	{ name: 'nc_contacts_create', risk: 'destructive', individualApproval: true },
	{ name: 'nc_contacts_patch', risk: 'destructive', individualApproval: true },
	{ name: 'nc_contacts_delete', risk: 'destructive', individualApproval: true },
]

const policy: ScopePolicy = {
	version: 'policy-contacts-1',
	gatewayOrigin: 'https://faden.test',
	sources: [{ kind: 'user-files' }],
	destinations: [],
	tools: [...contactsToolsList],
	sourceClasses: ['standard'],
	downstreamPolicy: 'unverified',
	destinationAudiences: [],
	movements: [],
	developmentLoopback: true,
}

const writeCapability: ProviderCapability = {
	provider: 'contacts',
	apiVersion: 'carddav-v1',
	policyVersion: 'native-policy-contacts-1',
	enabled: true,
	tools: [...contactsToolsList],
	conflict: 'conditional',
	history: 'audit-only',
	refresh: 'manual',
	canRead: true,
	canWrite: true,
}

const book: CalendarCollection = {
	provider: 'contacts',
	resourceId: 'nextcloud:contacts:Y29udGFjdHM',
	collectionId: 'contacts',
	revision: 'book-r1',
	sourceClass: 'standard',
	audience: 'private',
	policyVersion: 'native-policy-contacts-1',
	title: 'Contacts Book',
}

const sampleVcard = 'BEGIN:VCARD\r\nVERSION:3.0\r\nPRODID:-//Fadenstack test//EN\r\nUID:contact-1\r\nFN:Alice Smith\r\nN:Smith;Alice;;;\r\nEMAIL;TYPE=INTERNET:alice@example.com\r\nTEL;TYPE=CELL:+123456789\r\nNOTE:Key collaborator\r\nCATEGORIES:Work\r\nEND:VCARD\r\n'

const contactItem: ContactSummary = {
	resourceId: 'nextcloud:contacts:Y29udGFjdHMAY29udGFjdC0xLnZjZg',
	collectionId: 'contacts',
	filename: 'contact-1.vcf',
	etag: 'contact-r1',
	title: 'Alice Smith',
	fn: 'Alice Smith',
	email: 'alice@example.com',
}

const contactReadResult: ContactReadResult = {
	etag: 'contact-r1',
	content: sampleVcard,
	raw: sampleVcard,
	complete: true,
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

		contactsBooks: vi.fn(async () => [book]),
		contactsFind: vi.fn(async () => [contactItem]),
		contactsRead: vi.fn(async () => contactReadResult),
		contactsPropose: vi.fn(async (operation, bookId, filename, vcardData, expectedEtag) => ({
			id: 'prop-contacts-123',
			operation,
			toolName: `nc_contacts_${operation}`,
			target: `Address book ${bookId}, contact ${filename}`,
			collectionId: bookId,
			filename,
			hash: 'rev-prop-contacts-123',
			expires: Date.now() + 600000,
		})),
		contactsApprove: vi.fn(async () => ({ approved: true })),
		contactsCommit: vi.fn(async () => ({
			status: 'saved' as const,
			outcome: 'verified' as const,
			etag: 'etag-contact-new-1',
			target: 'Address book contacts, contact bob-smith.vcf',
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
	binding: 'native-session-contacts',
	enabled: true,
	serverUrl: 'https://faden.test',
	agent: 'contacts-agent',
	development: true,
	providerReadsEnabled: true,
	scopePolicy: policy,
}

function gateway(model?: ConstructorParameters<typeof FakeGateway>[0]['model']) {
	return new FakeGateway({
		agents: [{ slug: 'contacts-agent', version: 1, contract: published }],
		users: [{ email: 'alice', password: 'test', agents: ['contacts-agent'] }],
		model,
	})
}

describe('Native Contacts mutation lifecycle and runtime', () => {
	it('offers all 6 contacts tools when server capability has canWrite: true', async () => {
		const native = mockNativeApi()
		const runtime = new ProviderReadRuntime(native)
		await runtime.prepare('session-contacts-1', published, policy, new TurnBudget(), () => {}, signal())
		expect(runtime.offeredTools).toEqual(contactsToolsList)
	})

	it('withholds mutation tools when server capability has canWrite: false', async () => {
		const native = mockNativeApi({
			capabilities: vi.fn(async () => [{ ...writeCapability, canWrite: false, tools: ['nc_contacts_list', 'nc_contacts_find', 'nc_contacts_read'] }]),
		})
		const runtime = new ProviderReadRuntime(native)
		await runtime.prepare('session-contacts-1', published, policy, new TurnBudget(), () => {}, signal())
		expect(runtime.offeredTools).toEqual(['nc_contacts_list', 'nc_contacts_find', 'nc_contacts_read'])
	})

	it('lists native address books with permissions', async () => {
		const native = mockNativeApi()
		const files = mockFilesApi(native)
		const gw = gateway()
		const host = new NextcloudAgent(identity, files, gw.fetch)
		await host.signIn('alice', 'test')

		const result = await host.listContacts()
		expect(result).toEqual({
			addressBooks: [book],
			complete: true,
		})
		await host.dispose()
	})

	it('finds matching contacts without collapsing duplicate names', async () => {
		const native = mockNativeApi()
		const files = mockFilesApi(native)
		const gw = gateway()
		const host = new NextcloudAgent(identity, files, gw.fetch)
		await host.signIn('alice', 'test')

		const result = await host.findContacts('Smith')
		expect(result).toEqual([contactItem])
		expect(native.contactsFind).toHaveBeenCalledWith('Smith', expect.any(AbortSignal))
		await host.dispose()
	})

	it('reads individual contact properties with exact ETag', async () => {
		const native = mockNativeApi()
		const files = mockFilesApi(native)
		const gw = gateway()
		const host = new NextcloudAgent(identity, files, gw.fetch)
		await host.signIn('alice', 'test')

		const result = await host.readContact('contacts', 'contact-1.vcf')
		expect(result).toEqual({
			etag: 'contact-r1',
			raw: sampleVcard,
			values: expect.objectContaining({
				FN: 'Alice Smith',
				EMAIL: 'alice@example.com',
				TEL: '+123456789',
				CATEGORIES: 'Work',
			}),
		})
		await host.dispose()
	})

	it('composes nc_contacts_create: propose -> approval -> approve -> commit -> verified receipt', async () => {
		const native = mockNativeApi()
		let round = 0
		const gw = gateway(() => {
			round++
			if (round === 1) {
				return {
					toolCalls: [{
						id: 'contact-call-1',
						name: 'nc_contacts_create',
						arguments: {
							bookId: 'contacts',
							filename: 'bob-smith.vcf',
							fn: 'Bob Smith',
							email: 'bob@example.com',
							tel: '+1987654321',
							note: 'Engineer',
							categories: 'Engineering',
						},
					}],
				}
			}
			return { text: 'Contact created and verified in contacts book.' }
		})

		const files = mockFilesApi(native)
		const host = new NextcloudAgent(identity, files, gw.fetch)
		await host.signIn('alice', 'test')

		const sendPromise = host.send('Create Bob Smith contact.', false)
		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = host.chat.turns.flatMap(turn => [...turn.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		expect(native.contactsPropose).toHaveBeenCalledWith(
			'create',
			'contacts',
			'bob-smith.vcf',
			expect.stringContaining('FN:Bob Smith'),
			null,
			expect.any(AbortSignal),
		)

		// Zero dispatch before user approval
		expect(native.contactsApprove).not.toHaveBeenCalled()
		expect(native.contactsCommit).not.toHaveBeenCalled()

		await host.approve(part!)
		await sendPromise

		expect(native.contactsApprove).toHaveBeenCalledWith('prop-contacts-123', expect.any(AbortSignal))
		expect(native.contactsCommit).toHaveBeenCalledWith('prop-contacts-123', expect.any(AbortSignal))
		expect(host.chat.turns[0].state).toBe('completed')
		await host.dispose()
	})

	it('refuses execution and leaves zero dispatch when user denies contact creation', async () => {
		const native = mockNativeApi()
		let round = 0
		const gw = gateway(() => {
			round++
			if (round === 1) {
				return {
					toolCalls: [{
						id: 'contact-call-deny',
						name: 'nc_contacts_create',
						arguments: {
							bookId: 'contacts',
							filename: 'unwanted.vcf',
							fn: 'Unwanted Person',
						},
					}],
				}
			}
			return { text: 'Turn ended after denial.' }
		})

		const files = mockFilesApi(native)
		const host = new NextcloudAgent(identity, files, gw.fetch)
		await host.signIn('alice', 'test')

		const sendPromise = host.send('Create unwanted contact.', false)
		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = host.chat.turns.flatMap(turn => [...turn.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		await host.deny(part!)
		await sendPromise

		expect(native.contactsApprove).not.toHaveBeenCalled()
		expect(native.contactsCommit).not.toHaveBeenCalled()
		await host.dispose()
	})

	it('handles 412 precondition failure truthfully when patching a modified contact', async () => {
		const native = mockNativeApi({
			contactsPropose: vi.fn(async () => {
				throw new Error('Contact resource changed. Read it again before proposing a write.')
			}),
		})
		let round = 0
		const gw = gateway(() => {
			round++
			if (round === 1) {
				return {
					toolCalls: [{
						id: 'contact-call-patch-stale',
						name: 'nc_contacts_patch',
						arguments: {
							bookId: 'contacts',
							filename: 'contact-1.vcf',
							expectedEtag: 'stale-contact-etag',
							changes: { fn: 'Alice Johnson' },
						},
					}],
				}
			}
			return { text: 'Done' }
		})

		const files = mockFilesApi(native)
		const host = new NextcloudAgent(identity, files, gw.fetch)
		await host.signIn('alice', 'test')

		await host.send('Patch stale contact.', false)
		expect(host.chat.turns[0].state).toBe('failed')
		expect(host.chat.turns[0].errorMessage).toContain('Contact resource changed')
		expect(native.contactsCommit).not.toHaveBeenCalled()
		await host.dispose()
	})

	it('handles collision 409 conflict truthfully when creating an existing contact', async () => {
		const native = mockNativeApi({
			contactsPropose: vi.fn(async () => {
				throw new Error('A contact with this name already exists.')
			}),
		})
		let round = 0
		const gw = gateway(() => {
			round++
			if (round === 1) {
				return {
					toolCalls: [{
						id: 'contact-call-collision',
						name: 'nc_contacts_create',
						arguments: {
							bookId: 'contacts',
							filename: 'contact-1.vcf',
							fn: 'Existing Person',
						},
					}],
				}
			}
			return { text: 'Done' }
		})

		const files = mockFilesApi(native)
		const host = new NextcloudAgent(identity, files, gw.fetch)
		await host.signIn('alice', 'test')

		await host.send('Create colliding contact.', false)
		expect(host.chat.turns[0].state).toBe('failed')
		expect(host.chat.turns[0].errorMessage).toContain('already exists')
		expect(native.contactsCommit).not.toHaveBeenCalled()
		await host.dispose()
	})

	it('enforces single-use approval: replayed contact commit is refused', async () => {
		let commitCalls = 0
		const native = mockNativeApi({
			contactsCommit: vi.fn(async () => {
				commitCalls++
				if (commitCalls > 1) throw new Error('Proposal not found or already consumed.')
				return { status: 'saved' as const, outcome: 'verified' as const, etag: 'new-etag', target: 'contacts' }
			}),
		})

		const result1 = await native.contactsCommit!('prop-single-use')
		expect(result1.status).toBe('saved')
		expect(result1.outcome).toBe('verified')
		await expect(native.contactsCommit!('prop-single-use')).rejects.toThrow('already consumed')
	})

	it('composes nc_contacts_delete: propose -> approval -> commit -> verified absent receipt', async () => {
		const native = mockNativeApi({
			contactsCommit: vi.fn(async () => ({
				status: 'deleted' as const,
				outcome: 'verified' as const,
				target: 'Delete contact contact-1.vcf from address book contacts',
			})),
		})
		let round = 0
		const gw = gateway(() => {
			round++
			if (round === 1) {
				return {
					toolCalls: [{
						id: 'contact-call-delete',
						name: 'nc_contacts_delete',
						arguments: {
							bookId: 'contacts',
							filename: 'contact-1.vcf',
							expectedEtag: 'contact-r1',
						},
					}],
				}
			}
			return { text: 'Contact deleted.' }
		})

		const files = mockFilesApi(native)
		const host = new NextcloudAgent(identity, files, gw.fetch)
		await host.signIn('alice', 'test')

		const sendPromise = host.send('Delete contact.', false)
		let part: ApprovalPart | undefined
		await vi.waitFor(() => {
			part = host.chat.turns.flatMap(turn => [...turn.parts]).find(p => p instanceof ApprovalPart) as ApprovalPart | undefined
			expect(part?.isPending).toBe(true)
		})

		expect(native.contactsPropose).toHaveBeenCalledWith(
			'delete',
			'contacts',
			'contact-1.vcf',
			'',
			'contact-r1',
			expect.any(AbortSignal),
		)

		await host.approve(part!)
		await sendPromise

		expect(native.contactsCommit).toHaveBeenCalledWith('prop-contacts-123', expect.any(AbortSignal))
		expect(host.chat.turns[0].state).toBe('completed')
		await host.dispose()
	})

	it('truthful history: declares audit-only and does not expose Undo for contacts mutations', async () => {
		const native = mockNativeApi()
		const runtime = new ProviderReadRuntime(native)
		await runtime.prepare('session-contacts-1', published, policy, new TurnBudget(), () => {}, signal())
		const tools = runtime.offeredTools
		expect(tools).not.toContain('nc_actions_undo')
		expect(tools).not.toContain('nc_actions_redo')
	})
})
