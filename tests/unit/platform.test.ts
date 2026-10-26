import { describe, expect, it, vi } from 'vitest'
import { FILE_CAPABILITIES, TOOL_ROLLOUT, fileCapability } from '../../src/platform/catalog.ts'
import { TurnBudget } from '../../src/platform/limits.ts'
import { SourceLedger } from '../../src/platform/sourceLedger.ts'
import { TurnScope, fileReference, type NativeResource, type ScopeIdentity, type ScopePolicy, type FileSourceScope } from '../../src/platform/scope.ts'

const signal = new AbortController().signal
const a = 'nextcloud:files:21'
const b = 'nextcloud:files:22'
const folder = 'nextcloud:files:4'
const other = 'nextcloud:files:5'
const userFiles: FileSourceScope = { kind: 'user-files' }

function fixture(change: Partial<ScopePolicy> = {}, identityChange: Partial<ScopeIdentity> = {}) {
	let identity: ScopeIdentity = {
		nextcloudOrigin: 'https://cloud.example.test', nextcloudUser: 'alice', nextcloudBinding: 'native-binding',
		fadenstackOrigin: 'https://assistant.example.test', fadenstackPrincipal: { kind: 'session', id: 'sdk-sign-in-device' },
		agent: 'published-files', agentVersion: 3, schemaVersion: 'files-1', contractVersion: 'contract-3', policyVersion: 'policy-1',
		...identityChange,
	}
	const policy: ScopePolicy = {
		version: 'policy-1', sources: [userFiles], destinations: [userFiles],
		tools: FILE_CAPABILITIES.map(tool => tool.name), gatewayOrigin: 'https://assistant.example.test', sourceClasses: ['standard'],
		downstreamPolicy: 'unverified', destinationAudiences: ['private'], movements: [{ source: 'standard', destination: 'private' }],
		...change,
	}
	let resources = new Map<string, NativeResource>([
		[a, { resourceId: a, revision: 'revision-a', sourceClass: 'standard', audience: 'private', scope: policy.sources[0] }],
		[b, { resourceId: b, revision: 'revision-b', sourceClass: 'standard', audience: 'private', scope: policy.sources[0] }],
		[folder, { resourceId: folder, revision: 'folder-revision', sourceClass: 'standard', audience: 'private', scope: policy.destinations[0] ?? policy.sources[0] }],
	])
	const resolve = vi.fn(async (reference: string) => resources.get(reference))
	const published = FILE_CAPABILITIES.map(tool => ({ name: tool.name, risk: tool.risk }))
	const guard = new TurnScope(identity, policy, published, () => identity, resolve)
	return { guard, resolve, policy, published, identity: () => identity,
		changeIdentity: (change: Partial<ScopeIdentity>) => { identity = { ...identity, ...change } },
		changeResource: (reference: string, change: Partial<NativeResource>) => { resources.set(reference, { ...resources.get(reference)!, ...change }) },
		revoke: (reference: string) => { resources.delete(reference) },
	}
}

describe('reviewed host capability inventory', () => {
	it('preserves the nine Files names and conservative mutation risks without enabling the research catalog', () => {
		expect(FILE_CAPABILITIES).toHaveLength(9)
		expect(new Set(FILE_CAPABILITIES.map(tool => tool.name)).size).toBe(9)
		for (const name of ['nc_create_text_file', 'nc_update_text_file']) {
			expect(fileCapability(name)).toMatchObject({ risk: 'destructive', individualApproval: true, reversal: 'not-integrated' })
		}
		expect(fileCapability('nc_talk_send')).toBeUndefined()
		expect(TOOL_ROLLOUT.expandedCatalog).toBe('not-enabled')
		expect(Object.isFrozen(FILE_CAPABILITIES[0])).toBe(true)
	})

	it('offers only the intersection of native policy and published reviewed tools', () => {
		const f = fixture({ tools: ['nc_read_file', 'nc_update_text_file'] })
		const guard = new TurnScope(f.identity(), f.policy, [{ name: 'nc_read_file', risk: 'destructive' }], f.identity, f.resolve)
		expect(guard.offeredTools).toEqual(['nc_read_file'])
		expect(guard.beginTool('nc_read_file')).toBe('destructive')
		expect(() => guard.beginTool('nc_update_text_file')).toThrow('unavailable')
		expect(() => guard.beginTool('nc_talk_send')).toThrow('unavailable')
	})

	it('refuses unknown classes and ambiguous duplicate published contracts', () => {
		const f = fixture({ tools: ['nc_read_file'] })
		const invalid = new TurnScope(f.identity(), f.policy, [{ name: 'nc_read_file', risk: 'unknown' as never }], f.identity, f.resolve)
		expect(invalid.offeredTools).toEqual([])
		const duplicate = new TurnScope(f.identity(), f.policy, [{ name: 'nc_read_file', risk: 'read' }, { name: 'nc_read_file', risk: 'destructive' }], f.identity, f.resolve)
		expect(duplicate.offeredTools).toEqual([])
	})

	it('removes broad content search for restricted folders and all discovery for exact-file scopes', () => {
		const folders = fixture({ sources: [{ kind: 'folder', resourceId: folder }], destinations: [] }).guard
		expect(folders.offeredTools).toContain('nc_search_files')
		expect(folders.offeredTools).not.toContain('nc_search_content')
		expect(folders.offeredTools).not.toContain('nc_create_text_file')
		const exact = fixture({ sources: [{ kind: 'file', resourceId: a }] }).guard
		expect(exact.offeredTools).not.toContain('nc_search_files')
		expect(exact.offeredTools).not.toContain('nc_search_content')
		expect(exact.offeredTools).not.toContain('nc_list_folder')
	})
})

describe('conversation source classification', () => {
	it('retains protected classification after a move or native downgrade', () => {
		const f = fixture()
		const ledger = new SourceLedger()
		const resource: NativeResource = { resourceId: a, revision: 'old', sourceClass: 'protected', audience: 'private', scope: userFiles }
		ledger.record(resource)
		ledger.record({ ...resource, revision: 'new', sourceClass: 'standard' })
		expect(() => ledger.assertProcessing(f.policy)).toThrow('Earlier conversation content')
		expect(() => ledger.assertMovement(f.policy, 'private')).toThrow('Earlier conversation content')
		const permitted = { ...f.policy, sourceClasses: ['standard', 'protected'] as const, downstreamPolicy: 'configured' as const,
			movements: [...f.policy.movements, { source: 'protected' as const, destination: 'private' as const }] }
		expect(() => ledger.assertProcessing(permitted)).not.toThrow()
		expect(() => ledger.assertMovement(permitted, 'private')).not.toThrow()
	})

	it('isolates conversation copies and never treats unknown as allowed content', () => {
		const f = fixture({ sourceClasses: ['standard', 'unknown'], movements: [{ source: 'unknown', destination: 'private' }] })
		const ledger = new SourceLedger()
		ledger.record({ resourceId: a, revision: 'a', sourceClass: 'unknown', audience: 'private', scope: userFiles })
		const copy = new SourceLedger(ledger)
		ledger.clear()
		expect(copy.references).toEqual([a])
		expect(() => copy.assertProcessing(f.policy)).toThrow('Earlier conversation content')
		expect(() => copy.assertMovement(f.policy, 'private')).toThrow('Earlier conversation content')
		expect(ledger.references).toEqual([])
	})
})

describe('pinned turn and native resource scope', () => {
	it.each(['https://cloud.example.test/f/21', '../21', 'nextcloud:files:021', 'nextcloud:files:21/22', 'nextcloud:tables:21', 'nextcloud:files:0', 'nextcloud:files:9999999999999999'])('rejects a noncanonical or foreign reference: %s', value => {
		expect(() => fileReference(value)).toThrow()
	})

	it('blocks forged known-looking IDs before native access; native selection can bind a permitted source', async () => {
		const { guard, resolve } = fixture()
		await expect(guard.authorize(a, 'read', signal)).rejects.toThrow('included')
		expect(resolve).not.toHaveBeenCalled()
		await guard.bindSource(a, signal)
		await expect(guard.authorize(a, 'read', signal)).resolves.toMatchObject({ resourceId: a })
		expect(resolve).toHaveBeenCalledTimes(2)
		await expect(guard.authorize(b, 'read', signal)).rejects.toThrow('included')
	})

	it('does not let a source file grant access to another file or its parent destination', async () => {
		const { guard, resolve } = fixture({ sources: [{ kind: 'file', resourceId: a }], destinations: [{ kind: 'folder', resourceId: other }] })
		await guard.bindSource(a, signal)
		await expect(guard.bindSource(b, signal)).rejects.toThrow('outside')
		expect(resolve).toHaveBeenCalledTimes(1)
		await expect(guard.authorizeDestination(folder, signal)).rejects.toThrow('known destination')
	})

	it('narrows queries before discovery, rejects foreign folders and refuses ambiguous broad search', () => {
		const limited = fixture({ sources: [{ kind: 'folder', resourceId: folder }] }).guard
		expect(limited.searchScope()).toBe(folder)
		expect(limited.searchScope(folder)).toBe(folder)
		expect(() => limited.searchScope(other)).toThrow('configured')
		const multi = fixture({ sources: [{ kind: 'folder', resourceId: folder }, { kind: 'folder', resourceId: other }] }).guard
		expect(() => multi.searchScope()).toThrow('before searching')
		expect(multi.searchScope(other)).toBe(other)
	})

	it('rechecks cached sources and invalidates revoked references or changed revisions', async () => {
		const f = fixture()
		await f.guard.bindSource(a, signal)
		await f.guard.authorize(a, 'read', signal, 'revision-a')
		f.changeResource(a, { revision: 'human-edit' })
		await expect(f.guard.authorize(a, 'read', signal, 'revision-a')).rejects.toThrow('changed')
		f.revoke(a)
		await expect(f.guard.authorize(a, 'read', signal)).rejects.toThrow('unavailable')
		await expect(f.guard.authorize(a, 'metadata', signal)).rejects.toThrow('included')
	})

	it.each<Partial<ScopeIdentity>>([
		{ nextcloudUser: 'bob' }, { nextcloudBinding: 'new-session' }, { nextcloudOrigin: 'https://other.example.test' },
		{ fadenstackPrincipal: { kind: 'session', id: 'different-sign-in' } }, { agentVersion: 4 },
		{ schemaVersion: 'files-2' }, { contractVersion: 'changed-policy' }, { policyVersion: 'policy-2' },
	])('invalidates all turn references and tools when identity/profile/scope changes: %j', async change => {
		const f = fixture()
		await f.guard.bindSource(a, signal)
		f.changeIdentity(change)
		await expect(f.guard.authorize(a, 'read', signal)).rejects.toThrow('changed')
		expect(() => f.guard.beginTool('nc_read_file')).toThrow('changed')
	})

	it('rechecks drift and cancellation after an awaited native resolution', async () => {
		const f = fixture()
		f.resolve.mockImplementationOnce(async reference => { f.changeIdentity({ agentVersion: 99 }); return { resourceId: reference, revision: 'a', sourceClass: 'standard', audience: 'private', scope: userFiles } })
		await expect(f.guard.bindSource(a, signal)).rejects.toThrow('changed')
		const stopped = new AbortController(); stopped.abort()
		await expect(fixture().guard.bindSource(a, stopped.signal)).rejects.toThrow()
	})

	it('does not mistake an opaque SDK sign-in device for a durable user identity', () => {
		expect(fixture().guard.durablePrincipalBinding).toBe(false)
		expect(fixture({}, { fadenstackPrincipal: { kind: 'subject', id: 'verified-tenant:user' } }).guard.durablePrincipalBinding).toBe(true)
	})
})

describe('source processing and destination movement', () => {
	it('allows only the configured gateway origin and reauthorizes unchanged source bytes', async () => {
		const f = fixture()
		const source = await f.guard.bindSource(a, signal)
		await expect(f.guard.assertEgress('https://assistant.example.test', [source], signal)).resolves.toBeUndefined()
		await expect(f.guard.assertEgress('https://foreign.example.test', [source], signal)).rejects.toThrow('destination')
		await expect(f.guard.assertEgress('https://assistant.example.test', [{ ...source }], signal)).rejects.toThrow('binding')
		f.changeResource(a, { revision: 'edited-before-send' })
		await expect(f.guard.assertEgress('https://assistant.example.test', [source], signal)).rejects.toThrow('changed')
	})

	it('does not claim gateway allowlisting verifies a downstream provider for protected content', async () => {
		const f = fixture({ sourceClasses: ['standard', 'protected'], downstreamPolicy: 'unverified' })
		f.changeResource(a, { sourceClass: 'protected' })
		const source = await f.guard.bindSource(a, signal)
		await expect(f.guard.assertEgress('https://assistant.example.test', [source], signal)).rejects.toThrow('cannot be sent')
	})

	it('blocks unknown source classification even if a malformed policy lists it, including a mixed-source summary', async () => {
		const f = fixture({ sourceClasses: ['standard', 'unknown'], downstreamPolicy: 'configured' })
		f.changeResource(b, { sourceClass: 'unknown' })
		const sources = await Promise.all([f.guard.bindSource(a, signal), f.guard.bindSource(b, signal)])
		await expect(f.guard.assertEgress('https://assistant.example.test', sources, signal)).rejects.toThrow('cannot be sent')
	})

	it('permits an explicit private copy but blocks broader audiences, revoked sources and a changed destination', async () => {
		const f = fixture()
		const source = await f.guard.bindSource(a, signal)
		const destination = await f.guard.bindDestination(folder, signal)
		await expect(f.guard.assertMovement([source], destination, signal)).resolves.toBeUndefined()
		f.changeResource(folder, { audience: 'public' })
		await expect(f.guard.assertMovement([source], destination, signal)).rejects.toThrow('audience')
		f.changeResource(folder, { audience: 'private', revision: 'new-destination-state' })
		await expect(f.guard.assertMovement([source], destination, signal)).rejects.toThrow('changed')
		f.changeResource(folder, { revision: 'folder-revision' })
		f.revoke(a)
		await expect(f.guard.assertMovement([source], destination, signal)).rejects.toThrow('unavailable')
	})

	it('does not use destination native rights as permission to copy protected source content', async () => {
		const f = fixture()
		f.changeResource(a, { sourceClass: 'protected' })
		const source = await f.guard.bindSource(a, signal)
		const destination = await f.guard.bindDestination(folder, signal)
		await expect(f.guard.assertMovement([source], destination, signal)).rejects.toThrow('cannot be copied')
	})

	it.each(['https://user:password@assistant.example.test', 'https://assistant.example.test/path', 'http://external.example.test'])('rejects credentialed, nonorigin or insecure processing configuration: %s', destination => {
		expect(() => fixture({ gatewayOrigin: destination })).toThrow()
	})
})

describe('deterministic request budgets', () => {
	it('bounds call and mutation attempts without refunding failed or cancelled attempts', () => {
		const calls = new TurnBudget({ calls: 2, mutations: 1 })
		calls.begin(true)
		expect(() => calls.begin(true)).toThrow('action limit')
		calls.begin(false)
		expect(() => calls.begin(false)).toThrow('action limit')
		expect(calls.usage).toMatchObject({ calls: 2, mutations: 1 })
	})

	it('enforces UTF-8 cumulative result bytes and record counts before disclosure', () => {
		const bytes = new TurnBudget({ resultBytes: 8 })
		bytes.consumeResult('ü', 1)
		bytes.consumeResult('ü', 1)
		expect(() => bytes.consumeResult('x', 1)).toThrow('result limit')
		const records = new TurnBudget({ records: 2 })
		records.consumeResult([], 2)
		expect(() => records.consumeResult([], 1)).toThrow('result limit')
	})

	it('refuses larger limits, too many bulk items and an expired or backwards clock', () => {
		expect(() => new TurnBudget({ calls: 31 })).toThrow('cannot exceed')
		expect(() => new TurnBudget({ records: 0 })).toThrow()
		const budget = new TurnBudget({ bulkItems: 2 })
		expect(() => budget.begin(true, 3)).toThrow('item limit')
		let clock = 100
		const timed = new TurnBudget({ elapsedMs: 10 }, () => clock)
		clock = 110
		expect(() => timed.begin(false)).toThrow('time limit')
		clock = 99
		expect(() => timed.begin(false)).toThrow('time limit')
	})
})
