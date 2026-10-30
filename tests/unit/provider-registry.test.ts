import { describe, expect, it } from 'vitest'
import { ProviderRegistry, type ProviderCapability } from '../../src/providers/registry.ts'
import { ProviderTurnScope, type ProviderResource } from '../../src/providers/scope.ts'
import { TurnBudget } from '../../src/platform/limits.ts'

const registry = new ProviderRegistry([{ provider: 'calendar', apiVersions: ['caldav-v1'], tools: ['nc_calendar_read', 'nc_calendar_create'] }])
const capability: ProviderCapability = { provider: 'calendar', apiVersion: 'caldav-v1', policyVersion: 'native-policy-1', enabled: true,
	tools: ['nc_calendar_read', 'nc_calendar_create'], conflict: 'conditional', history: 'audit-only', refresh: 'manual', canRead: true, canWrite: true }
const published = [{ name: 'nc_calendar_read', risk: 'read' as const }, { name: 'nc_calendar_create', risk: 'write' as const }]

describe('Provider runtime capability intersection', () => {
	it('requires a real implementation and current native/published grants', () => {
		expect(registry.available([], published)).toEqual([])
		expect(registry.available([capability], [])).toEqual([])
		expect(new ProviderRegistry([]).available([capability], published)).toEqual([])
		expect(registry.available([capability], published).map(tool => tool.name)).toEqual(['nc_calendar_read', 'nc_calendar_create'])
	})
	it('keeps the existing stricter risk and individual approval', () => {
		const create = registry.available([capability], published)[1]
		expect(create.risk).toBe('destructive')
		expect(create.individualApproval).toBe(true)
	})
	it('cannot advertise an inverse from native metadata alone or for native conflict semantics', () => {
		expect(registry.available([{ ...capability, history: 'certified' }], published)[1].capability.history).toBe('audit-only')
		const certified = new ProviderRegistry([{ provider: 'calendar', apiVersions: ['caldav-v1'],
			tools: ['nc_calendar_create'], certifiedHistoryTools: ['nc_calendar_create'] }])
		expect(certified.available([{ ...capability, history: 'certified' }], published)[0].capability.history).toBe('certified')
		expect(certified.available([{ ...capability, history: 'certified', conflict: 'native' }], published)[0].capability.history).toBe('audit-only')
	})
	it.each([{ enabled: false }, { canRead: false }, { apiVersion: 'untested' }, { policyVersion: '' },
		{ enabled: 'true' }, { conflict: 'pretend-atomic' }, { tools: ['nc_calendar_read', 'nc_calendar_read'] }])('fails closed for invalid native capability %j', change => {
		expect(registry.available([{ ...capability, ...change } as ProviderCapability], published)).toEqual([])
	})
	it('does not turn read permission or read-only transport into a write grant', () => {
		for (const change of [{ canWrite: false }, { conflict: 'read-only' as const }]) {
			expect(registry.available([{ ...capability, ...change }], published).map(tool => tool.name)).toEqual(['nc_calendar_read'])
		}
	})
	it('refuses ambiguous published contracts and duplicate native descriptors', () => {
		expect(registry.available([capability], [...published, published[0]]).map(tool => tool.name)).toEqual(['nc_calendar_create'])
		expect(() => registry.available([capability, capability], published)).toThrow('Duplicate')
	})
	it('rejects cross-provider implementation registrations', () => {
		expect(() => new ProviderRegistry([{ provider: 'calendar', apiVersions: ['v1'], tools: ['nc_mail_read'] }])).toThrow('Invalid')
	})
})

describe('Provider turn authority and aggregate budgets', () => {
	const resource: ProviderResource = { provider: 'calendar', resourceId: 'nextcloud:calendar:YWxpY2UtcHJpdmF0ZQ',
		collectionId: 'private', revision: 'etag1', sourceClass: 'standard', audience: 'private', policyVersion: 'native-policy-1' }
	const signal = new AbortController().signal
	const tools = registry.available([capability], published)
	it('does not accept a guessed valid-looking identifier before native discovery', async () => {
		const scope = new ProviderTurnScope(tools, new TurnBudget(), () => {}, async value => value)
		await expect(scope.authorize(resource.resourceId, 'calendar', false, signal)).rejects.toThrow('native discovery')
		scope.bind(resource)
		expect(await scope.authorize(resource.resourceId, 'calendar', false, signal)).toEqual(resource)
		await expect(scope.authorize('https://cloud.test/private', 'calendar', false, signal)).rejects.toThrow('never a path')
	})
	it.each(['revision', 'collectionId', 'audience', 'policyVersion', 'sourceClass'] as const)('rejects changed native %s', async field => {
		const scope = new ProviderTurnScope(tools, new TurnBudget(), () => {}, async value => ({ ...value, [field]: 'changed' }))
		scope.bind(resource)
		await expect(scope.authorize(resource.resourceId, 'calendar', true, signal)).rejects.toThrow('changed')
	})
	it('shares limits with Files and does not refund an attempt', () => {
		const budget = new TurnBudget({ calls: 2 })
		budget.begin(false)
		const scope = new ProviderTurnScope(tools, budget, () => {}, async value => value)
		scope.begin('nc_calendar_read')
		expect(() => scope.begin('nc_calendar_create')).toThrow('action limit')
	})
	it('rechecks identity after an asynchronous native request and invalidates on navigation', async () => {
		let current = true
		const scope = new ProviderTurnScope(tools, new TurnBudget(), () => { if (!current) throw new Error('identity changed') }, async value => { current = false; return value })
		scope.bind(resource)
		await expect(scope.authorize(resource.resourceId, 'calendar', false, signal)).rejects.toThrow('identity changed')
		scope.invalidate()
		expect(() => scope.begin('nc_calendar_read')).toThrow('context changed')
	})
})
