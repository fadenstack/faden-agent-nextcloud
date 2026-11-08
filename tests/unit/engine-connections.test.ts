import { describe, expect, it, vi } from 'vitest'
import { changedEngineConnection, engineConnectionList, engineConnectionSummary } from '../../src/providers/engineConnections.ts'
import { nativeProvidersApi } from '../../src/providers/client.ts'

const original = { id: 'engine-main', provider: 'n8n', label: 'My workflows', baseUrl: 'https://engine.example.test', workspace: '', version: '2.43.8', revision: 'a'.repeat(64), connected: false, setupAllowed: true, expiresAt: null, keyAccess: 'Key covers all resources of this n8n account.' }

describe('human workflow connections', () => {
	it('returns bounded metadata without permitting a credential field', () => {
		expect(engineConnectionSummary(original)).toEqual(original)
		expect(() => engineConnectionSummary({ ...original, apiToken: 'must-not-be-returned' })).toThrow('Invalid workflow connection metadata.')
		expect(() => engineConnectionList({ connections: [original], token: 'must-not-be-returned' })).toThrow()
	})
	it('rejects duplicate, oversized and malformed connection metadata', () => {
		expect(() => engineConnectionList({ connections: [original, original] })).toThrow('Duplicate')
		expect(() => engineConnectionList({ connections: Array(65).fill(original) })).toThrow('Narrow')
		for (const value of [{ ...original, revision: 'unknown' }, { ...original, id: '../admin' }, { ...original, expiresAt: NaN }, { ...original, provider: 'unavailable', connected: true }]) expect(() => engineConnectionSummary(value)).toThrow()
	})
	it('refuses a wrong target, stale generation or unverified change', () => {
		const changed = { ...original, connected: true, revision: 'b'.repeat(64) }
		expect(changedEngineConnection(changed, original.id, original.revision, true)).toEqual(changed)
		for (const value of [{ ...changed, id: 'another-connection' }, { ...changed, revision: original.revision }, { ...changed, connected: false }]) expect(() => changedEngineConnection(value, original.id, original.revision, true)).toThrow('could not be confirmed')
	})
	it('uses fixed native human endpoints and never returns the supplied token', async () => {
		const sent: { path: string; body: unknown }[] = []
		const post = vi.fn(async (path: string, body: unknown) => {
			sent.push({ path, body })
			return path.endsWith('/connect') ? { ...original, connected: true, revision: 'b'.repeat(64) } : path.endsWith('/disconnect') ? { ...original, revision: 'c'.repeat(64) } : { connections: [original] }
		})
		const api = nativeProvidersApi(post as never)
		const signal = new AbortController().signal
		await api.engineConnections!(signal)
		const connected = await api.engineConnect!(original.id, original.revision, 'synthetic-private-token', signal)
		expect(JSON.stringify(connected)).not.toContain('synthetic-private-token')
		await api.engineDisconnect!(original.id, connected.revision, signal)
		expect(sent.map(item => item.path)).toEqual(['/engines/connections', '/engines/connections/connect', '/engines/connections/disconnect'])
		expect(sent[2]!.body).toEqual({ id: original.id, revision: connected.revision })
	})
})
