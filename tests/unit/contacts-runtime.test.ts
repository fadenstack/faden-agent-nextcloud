import { describe, expect, it, vi } from 'vitest'
import { FakeGateway, lastToolResults } from '@fadenstack/testing'
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
	...book,
	provider: 'contacts',
	resourceId: 'nextcloud:contacts:Y29udGFjdHMAY29udGFjdC0xLnZjZg',
	collectionId: 'contacts',
	filename: 'contact-1.vcf',
	revision: 'contact-r1',
	content: sampleVcard,
	title: 'Alice Smith',
	fn: 'Alice Smith',
	email: 'alice@example.com',
}

const contactReadResult: ContactReadResult = {
	...contactItem,
	etag: 'contact-r1',
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
		contactsRead: vi.fn(async (_book, filename) => filename ? contactReadResult : { contacts: [contactItem], complete: true }),
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

async function readThroughSdk(native: NativeProvidersApi, name: string, args: Record<string, unknown>) {
	let round = 0
	let result: unknown
	const gw = gateway(request => {
		if (++round === 1) return { toolCalls: [{ id: 'read', name, arguments: args }] }
		result = JSON.parse(lastToolResults(request).get('read')!)
		return { text: 'Read completed.' }
	})
	const host = new NextcloudAgent(identity, mockFilesApi(native), gw.fetch)
	try {
		await host.signIn('alice', 'test')
		await host.send('Read the synthetic contacts.', false)
		expect(host.chat.turns[0].state).toBe('completed')
		expect(round).toBe(2)
		return result
	} finally {
		await host.dispose()
	}
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
		const result = await readThroughSdk(native, 'nc_contacts_list', {})
		expect(result).toEqual({
			addressBooks: [book],
			complete: true,
		})
	})

	it('finds matching contacts without collapsing duplicate names', async () => {
		const native = mockNativeApi()
		const result = await readThroughSdk(native, 'nc_contacts_find', { query: 'Smith' })
		expect(result).toEqual([contactItem])
		expect(native.contactsFind).toHaveBeenCalledWith('Smith', expect.any(AbortSignal))
	})

	it('reads individual contact properties with exact ETag', async () => {
		const native = mockNativeApi()
		const result = await readThroughSdk(native, 'nc_contacts_read', { bookId: 'contacts', filename: 'contact-1.vcf' })
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
	})

	it('reads a bounded address book through the composed SDK', async () => {
		expect(await readThroughSdk(mockNativeApi(), 'nc_contacts_read', { bookId: 'contacts' })).toEqual([contactItem])
	})

	it.each([
		['individual', 'nc_contacts_read', { bookId: 'contacts', filename: 'contact-1.vcf' }],
		['whole book', 'nc_contacts_read', { bookId: 'contacts' }],
		['search', 'nc_contacts_find', { query: 'Smith' }],
	] as const)('blocks the model follow-up after %s contact access is revoked', async (_label, name, args) => {
		const native = mockNativeApi()
		vi.mocked(native.contactsRead!).mockImplementation(async (_book, filename) => {
			if (!filename) return { contacts: [contactItem], complete: true }
			throw new Error('Native contact access was revoked.')
		})
		if (args.filename) vi.mocked(native.contactsRead!).mockResolvedValueOnce(contactReadResult)
		let calls = 0
		const gw = gateway(() => ++calls === 1
			? { toolCalls: [{ id: 'read', name, arguments: args }] }
			: { text: 'This request must never be reached.' })
		const host = new NextcloudAgent(identity, mockFilesApi(native), gw.fetch)
		try {
			await host.signIn('alice', 'test')
			await host.send('Read a contact.', false)
			expect(host.chat.turns[0].state).toBe('failed')
			expect(host.chat.turns[0].errorMessage).toContain('revoked')
			expect(calls).toBe(1)
		} finally { await host.dispose() }
	})

	it('refuses native reads outside a governed provider turn before fetching content', async () => {
		const native = mockNativeApi()
		const host = new NextcloudAgent(identity, mockFilesApi(native), gateway().fetch)
		await host.signIn('alice', 'test')
		await expect(host.readContact('contacts', 'contact-1.vcf')).rejects.toThrow('turn is unavailable')
		expect(native.contactsRead).not.toHaveBeenCalled()
		await host.dispose()
	})

	it.each(['revision', 'audience', 'sourceClass', 'policyVersion'] as const)('rechecks historical contact %s before a later model request', async field => {
		const native = mockNativeApi()
		const runtime = new ProviderReadRuntime(native)
		await runtime.prepare('contact-history', published, policy, new TurnBudget(), () => {}, signal())
		runtime.recordSource(contactReadResult)
		runtime.finish('contact-history')
		const changes = { revision: 'contact-r2', audience: 'public', sourceClass: 'protected', policyVersion: 'new-policy' } as const
		vi.mocked(native.contactsRead!).mockResolvedValue({ ...contactReadResult, [field]: changes[field] })
		await expect(runtime.prepare('contact-history', published, policy, new TurnBudget(), () => {}, signal())).rejects.toThrow('changed')
	})

	it('rejects an unresolvable contact reference before retaining its body', async () => {
		const runtime = new ProviderReadRuntime(mockNativeApi())
		await runtime.prepare('bad-reference', published, policy, new TurnBudget(), () => {}, signal())
		expect(() => runtime.recordSource({ ...contactItem, filename: 'other.vcf' })).toThrow('reference is invalid')
		expect(runtime.hasSources).toBe(false)
	})

	it('rejects a late response after the provider turn has changed', async () => {
		const runtime = new ProviderReadRuntime(mockNativeApi())
		await runtime.prepare('old-contact-turn', published, policy, new TurnBudget(), () => {}, signal())
		const oldGuard = runtime.readGuard()
		runtime.finish('old-contact-turn')
		await runtime.prepare('new-contact-turn', published, policy, new TurnBudget(), () => {}, signal())
		expect(oldGuard).toThrow('turn changed')
		expect(runtime.hasSources).toBe(false)
	})

	it('validates local approval preimages without treating them as disclosed model output', async () => {
		const runtime = new ProviderReadRuntime(mockNativeApi())
		await runtime.prepare('local-proposal', published, policy, new TurnBudget(), () => {}, signal())
		runtime.inspectLocalSource(contactReadResult)
		expect(runtime.hasSources).toBe(false)
		expect(() => runtime.inspectLocalSource({ ...contactReadResult, sourceClass: 'unknown' })).toThrow('cannot be sent')
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
		vi.mocked(native.contactsCommit!).mockImplementation(async () => {
			// The committed object really becomes unreadable; only the local preview used its body.
			vi.mocked(native.contactsRead!).mockRejectedValue(new Error('The contact no longer exists.'))
			return { status: 'deleted', outcome: 'verified', target: 'contacts/contact-1.vcf' }
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
		expect(native.contactsRead).toHaveBeenCalledTimes(1)
		expect(JSON.stringify(gw.modelCalls.at(-1)?.messages)).not.toContain('Key collaborator')
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
