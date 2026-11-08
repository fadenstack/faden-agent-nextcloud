import { describe, expect, it, vi } from 'vitest'
import { FakeGateway } from '@fadenstack/testing'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { nativeProvidersApi, type NativeProvidersApi } from '../../src/providers/client.ts'
import type { FileApi, Identity } from '../../src/integrations/nextcloud/types.ts'
import type { ProviderCapability } from '../../src/providers/registry.ts'
import type { ProviderResource } from '../../src/providers/scope.ts'

const providers = ['windmill', 'n8n'] as const
const tools = providers.map(provider => `nc_${provider}_read`)
const identity: Identity = { userId: 'alice', binding: 'native-session', enabled: true, agent: 'engines', serverUrl: 'https://faden.test', development: true, providerReadsEnabled: true,
	scopePolicy: { version: 'test', gatewayOrigin: 'https://faden.test', sources: [{ kind: 'user-files' }], destinations: [], tools, sourceClasses: ['standard'], downstreamPolicy: 'unverified', destinationAudiences: [], movements: [], developmentLoopback: true } }

async function run(provider: typeof providers[number], mode = '') {
	let round = 0
	let revision = 'revision-1'
	const offered: string[][] = []
	const metadata: ProviderResource = { provider, resourceId: `nextcloud:${provider}:e30`, collectionId: 'connections', revision: 'revision-1', policyVersion: 'engine-policy', sourceClass: 'standard', audience: 'unknown', filename: '{}' }
	const capability: ProviderCapability = { provider, apiVersion: `${provider}-${provider === 'windmill' ? '1.841.0' : '2.43.8'}-token`, enabled: true, policyVersion: 'engine-policy', tools: [`nc_${provider}_read`], conflict: 'read-only', history: 'not-applicable', refresh: 'manual', canRead: true, canWrite: false }
	const native: NativeProvidersApi = {
		calendarCollections: vi.fn(async () => []), calendarEvents: vi.fn(async () => []),
		capabilities: vi.fn(async () => mode === 'missing' ? [] : [{ ...capability, ...(mode === 'version' ? { apiVersion: 'untested-v99' } : {}) }]),
		contentRead: vi.fn(async () => ({ resource: { ...metadata, revision }, connections: [{ connectionId: 'own-connection' }] })),
	}
	const files: FileApi = { identity: async () => identity, assertIdentity: async () => {}, select: vi.fn(), read: vi.fn(), list: vi.fn(), pdf: vi.fn(), propose: vi.fn(), approve: vi.fn(), commit: vi.fn(), scopeResource: vi.fn(), nativeProviders: native }
	const contract = tools.filter(name => mode !== 'unpublished' || name !== `nc_${provider}_read`).map(name => ({ name, risk: 'read' as const }))
	const gateway = new FakeGateway({ serverUrl: identity.serverUrl, agents: [{ slug: identity.agent, version: 1, contract }], users: [{ email: 'alice', password: 'test', agents: [identity.agent] }], model: request => {
		offered.push(request.tools.map(tool => tool.name)); round++
		if (mode === 'unpublished') return { text: 'This tool is not published for the agent.' }
		return round === 1 ? { toolCalls: [{ id: 'discover', name: `nc_${provider}_read`, arguments: mode === 'invented-url' ? { url: 'https://untrusted.test' } : {} }] } : { text: 'Authorized engine connections read.' }
	} })
	const host = new NextcloudAgent(mode === 'gate-off' ? { ...identity, providerReadsEnabled: false } : identity, files, gateway.fetch)
	await host.signIn('alice', 'test')
	return { host, native, files, offered, send: () => host.send('Read my workflow connections.', false), revoke: () => { revision = 'new-revision' } }
}

describe('Engine reads through the composed published SDK', () => {
	it.each(providers)('discovers %s through the native read pipeline without legacy transports or writes', async provider => {
		const test = await run(provider)
		try {
			await test.send()
			expect(test.host.chat.turns[0].state).toBe('completed')
			expect(test.offered[0].filter(name => name.startsWith(`nc_${provider}_`))).toEqual([`nc_${provider}_read`])
			expect(test.offered[0]).not.toEqual(expect.arrayContaining([`nc_${provider}_draft`, `nc_${provider}_run`, 'nc_n8n_execute']))
			expect(test.native.contentRead).toHaveBeenCalledWith(provider, {}, expect.any(AbortSignal))
			expect(test.files.commit).not.toHaveBeenCalled()
		} finally { await test.host.dispose() }
	})
	it.each(['missing', 'version', 'unpublished', 'gate-off'])('withholds engines when %s and does not revive a legacy route', async mode => {
		const test = await run('windmill', mode)
		try {
			await test.send()
			expect(test.offered[0]).not.toContain('nc_windmill_read')
			expect(test.native.contentRead).not.toHaveBeenCalled()
		} finally { await test.host.dispose() }
	})
	it('rejects model-supplied URLs before reaching the native adapter', async () => {
		const test = await run('n8n', 'invented-url')
		try { await test.send(); expect(test.native.contentRead).not.toHaveBeenCalled() }
		finally { await test.host.dispose() }
	})
	it('rechecks a retained connection before a subsequent model turn', async () => {
		const test = await run('n8n')
		try {
			await test.send(); const before = test.offered.length
			test.revoke(); await expect(test.send()).rejects.toThrow('changed')
			expect(test.offered.length).toBe(before)
		} finally { await test.host.dispose() }
	})
	it('uses one fixed native engine route with no credential or HTTP selector', async () => {
		const post = vi.fn(async () => ({ resource: {} }))
		const native = nativeProvidersApi(post as Parameters<typeof nativeProvidersApi>[0])
		const signal = new AbortController().signal
		await native.contentRead!('windmill', { connectionId: 'own', reference: 'u/alice/test' }, signal)
		expect(post).toHaveBeenCalledExactlyOnceWith('/providers/engines/read', { provider: 'windmill', args: { connectionId: 'own', reference: 'u/alice/test' } }, signal)
	})
})
