import { describe, expect, it, vi } from 'vitest'
import { changedEngineConnection, engineConnectionList, engineConnectionSummary, engineWebhookList, engineOAuthList, engineOAuthAuthorization, type EngineOAuthSummary } from '../../src/providers/engineConnections.ts'
import { nativeProvidersApi } from '../../src/providers/client.ts'
import { EngineSurface } from '../../src/workspace/engineSurface.ts'
import { createApp, markRaw } from 'vue'
import EngineConnections from '../../src/components/EngineConnections.vue'

// Native modal focus/transition behavior is exercised in the real browser.
// Keep this regression about credential controls independent of Happy DOM's
// delayed focus-trap transition after a fixture is unmounted.
vi.mock('@nextcloud/vue/components/NcDialog', () => ({ default: { template: '<div role="dialog"><slot /><slot name="actions" /></div>' } }))

const original = { id: 'engine-main', provider: 'n8n', label: 'My workflows', baseUrl: 'https://engine.example.test', workspace: '', version: '2.43.8', revision: 'a'.repeat(64), connected: false, setupAllowed: true, expiresAt: null, keyAccess: 'Key covers all resources of this n8n account.' }

describe('human workflow connections', () => {
	it('removes the one-use authorization link when a reloaded grant becomes connected', async () => {
		vi.stubGlobal('ResizeObserver', class { observe() {} unobserve() {} disconnect() {} })
		let state: EngineOAuthSummary['state'] = 'disconnected'
		const target = { id: 'own-oauth', connectionId: original.id, workflowId: 'flow1', issuer: 'https://editor.example.test', expiresAt: null }
		const api = markRaw({ engineConnections: async () => [{ ...original, connected: true }], engineWebhooks: async () => [],
			engineOAuth: async () => [{ ...target, state }], engineOAuthStart: async () => { state = 'pending'; return 'https://editor.example.test/mcp-oauth/authorize' } })
		const root = document.createElement('div'); document.body.append(root)
		const app = createApp(EngineConnections, { api: api as never })
		try {
			app.mount(root)
			const button = (name: string) => [...root.querySelectorAll('button')].find(b => b.textContent?.trim() === name)
			await vi.waitFor(() => expect(button('Connect webhook OAuth')).toBeDefined())
			button('Connect webhook OAuth')!.click()
			await vi.waitFor(() => expect(root.textContent).toContain('Continue to n8n'))
			state = 'connected'; button('Reload connections')!.click()
			await vi.waitFor(() => expect(button('Refresh OAuth grant')).toBeDefined())
			expect([...root.querySelectorAll('a')].some(a => a.textContent?.trim() === 'Continue to n8n')).toBe(false)
		} finally { app.unmount(); root.remove(); vi.unstubAllGlobals() }
	})
	it.each(['failed', 'pending'])('keeps Disconnect available when optional OAuth metadata is %s', async mode => {
		vi.stubGlobal('ResizeObserver', class { observe() {} unobserve() {} disconnect() {} })
		const disconnect = vi.fn(async () => ({ ...original, connected: false, revision: 'b'.repeat(64) }))
		const api = markRaw({ engineConnections: async () => [{ ...original, connected: true }], engineWebhooks: async () => [], engineDisconnect: disconnect,
			engineOAuth: () => mode === 'failed' ? Promise.reject(new Error('OAuth unavailable')) : new Promise(() => {}) })
		const root = document.createElement('div'); document.body.append(root)
		const app = createApp(EngineConnections, { api: api as never })
		try {
			app.mount(root)
			let button: HTMLButtonElement | undefined
			await vi.waitFor(() => { button = [...document.querySelectorAll('button')].find(b => b.textContent?.trim() === 'Disconnect'); expect(button?.disabled).toBe(false) })
			button!.click()
			await vi.waitFor(() => expect(disconnect).toHaveBeenCalledExactlyOnceWith(original.id, original.revision, expect.any(AbortSignal)))
		} finally { app.unmount(); root.remove(); vi.unstubAllGlobals() }
	})
	it('accepts bounded OAuth metadata and refuses returned tokens or duplicate targets', () => {
		const row: EngineOAuthSummary = { id: 'own-oauth', connectionId: 'engine-main', workflowId: 'flow1', issuer: 'https://editor.example.test', state: 'connected', expiresAt: 1234 }
		expect(engineOAuthList({ targets: [row] })).toEqual([row])
		for (const targets of [[{ ...row, accessToken: 'never-return' }], [row, row], [{ ...row, state: 'verified-delivery' }], [{ ...row, issuer: 'javascript:alert(1)' }]]) expect(() => engineOAuthList({ targets })).toThrow()
	})
	it('checks exact native authorization route, one state, PKCE and local callback without exposing the verifier', () => {
		const row: EngineOAuthSummary = { id: 'own-oauth', connectionId: 'engine-main', workflowId: 'flow1', issuer: 'https://editor.example.test', state: 'pending', expiresAt: null }
		const url = new URL(row.issuer + '/mcp-oauth/authorize')
		for (const [key, value] of Object.entries({ client_id: 'own-client', response_type: 'code', redirect_uri: location.origin + '/index.php/apps/fadenstack_agent/engines/oauth/callback/engine-main', code_challenge: 'a'.repeat(43), code_challenge_method: 'S256', state: 'b'.repeat(64), resource: row.issuer + '/webhook/own-hook?method=POST' })) url.searchParams.set(key, value)
		expect(engineOAuthAuthorization({ authorizationUrl: url.href }, row)).toBe(url.href)
		for (const [key, value] of [['redirect_uri', 'https://elsewhere.test/callback'], ['code_challenge_method', 'plain'], ['resource', 'https://elsewhere.test/webhook/hook?method=POST'], ['state', 'short'], ['code_verifier', 'never-return']]) {
			const bad = new URL(url); bad.searchParams.set(key!, value!)
			expect(() => engineOAuthAuthorization({ authorizationUrl: bad.href }, row)).toThrow()
		}
		const duplicate = new URL(url); duplicate.searchParams.append('state', 'b'.repeat(64))
		expect(() => engineOAuthAuthorization({ authorizationUrl: duplicate.href }, row)).toThrow()
	})
	it.each(['failed', 'pending'])('keeps Disconnect available when optional native workspace navigation is %s', async mode => {
		vi.stubGlobal('ResizeObserver', class { observe() {} unobserve() {} disconnect() {} })
		const disconnect = vi.fn(async () => ({ ...original, connected: false, revision: 'b'.repeat(64) }))
		const api = markRaw({ engineConnections: async () => [{ ...original, connected: true }], engineWebhooks: async () => [], engineDisconnect: disconnect,
			engineWorkspaces: () => mode === 'failed' ? Promise.reject(new Error('Native view unavailable')) : new Promise(() => {}) })
		const root = document.createElement('div'); document.body.append(root)
		const app = createApp(EngineConnections, { api: api as never })
		try {
			app.mount(root)
			let button: HTMLButtonElement | undefined
			await vi.waitFor(() => { button = [...document.querySelectorAll('button')].find(b => b.textContent?.trim() === 'Disconnect'); expect(button?.disabled).toBe(false) })
			button!.click()
			await vi.waitFor(() => expect(disconnect).toHaveBeenCalledExactlyOnceWith(original.id, original.revision, expect.any(AbortSignal)))
		} finally { app.unmount(); root.remove(); vi.unstubAllGlobals() }
	})
	it('rejects secrets, duplicate IDs and generic routes in webhook metadata', () => {
		const row = { id: 'own-hook', connectionId: 'own', workflowId: 'flow1', path: 'own-hook', secretStored: true }
		expect(engineWebhookList({ targets: [row] })).toEqual([row])
		for (const targets of [[{ ...row, secret: 'must-not-return' }], [{ ...row, path: '../escape' }], [row, row]]) expect(() => engineWebhookList({ targets })).toThrow()
	})
	it('stores webhook secrets only through a fixed human endpoint with generation binding', async () => {
		const post = vi.fn(async () => ({ ...original, connected: true, revision: 'b'.repeat(64) }))
		const api = nativeProvidersApi(post as never), signal = new AbortController().signal
		const result = await api.engineWebhookChange!('own-hook', original.id, original.revision, 'synthetic-private-hook-key', signal)
		expect(post).toHaveBeenCalledExactlyOnceWith('/engines/webhooks/change', { targetId: 'own-hook', revision: original.revision, secret: 'synthetic-private-hook-key' }, signal)
		expect(JSON.stringify(result)).not.toContain('synthetic-private-hook-key')
	})
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
	it('resynchronizes connection revision after a failed OAuth refresh so local removal and disconnect never strand', async () => {
		vi.stubGlobal('ResizeObserver', class { observe() {} unobserve() {} disconnect() {} })
		let currentRevision = original.revision
		let oauthState: EngineOAuthSummary['state'] = 'connected'
		const target = { id: 'own-oauth', connectionId: original.id, workflowId: 'flow1', issuer: 'https://editor.example.test', expiresAt: 1999999999 }
		const removeOAuth = vi.fn(async (_tid: string, _cid: string, rev: string) => {
			if (rev !== currentRevision) throw new Error('Stale revision 409')
			currentRevision = 'c'.repeat(64)
			oauthState = 'disconnected'
			return { ...original, connected: true, revision: currentRevision }
		})
		const api = markRaw({
			engineConnections: async () => [{ ...original, connected: true, revision: currentRevision }],
			engineWebhooks: async () => [],
			engineOAuth: async () => [{ ...target, state: oauthState }],
			engineOAuthRefresh: async () => {
				currentRevision = 'b'.repeat(64)
				oauthState = 'reconnect'
				throw new Error('Remote OIDC refresh rejected (consent revoked)')
			},
			engineOAuthRemove: removeOAuth,
		})
		const root = document.createElement('div'); document.body.append(root)
		const app = createApp(EngineConnections, { api: api as never })
		try {
			app.mount(root)
			const button = (name: string) => [...root.querySelectorAll('button')].find(b => b.textContent?.trim() === name)
			await vi.waitFor(() => expect(button('Refresh OAuth grant')).toBeDefined())
			button('Refresh OAuth grant')!.click()
			await vi.waitFor(() => expect(root.textContent).toContain('OAuth is unconfirmed'))
			await vi.waitFor(() => expect(button('Remove local OAuth access')).toBeDefined())
			button('Remove local OAuth access')!.click()
			await vi.waitFor(() => expect(removeOAuth).toHaveBeenCalledExactlyOnceWith('own-oauth', original.id, 'b'.repeat(64), expect.any(AbortSignal)))
			await vi.waitFor(() => expect(root.textContent).toContain('Local OAuth access and pending consent removed'))
		} finally { app.unmount(); root.remove(); vi.unstubAllGlobals() }
	})
	it('invalidates the active EngineSurface target and result state when the connection is rotated or disconnected', async () => {
		vi.stubGlobal('ResizeObserver', class { observe() {} unobserve() {} disconnect() {} })
		const surface = new EngineSurface({ connectionId: original.id, provider: 'n8n', label: 'My workflows', url: 'https://editor.example.test', origin: 'https://editor.example.test', revision: 'f'.repeat(64), apiPrincipal: '11111111-2222-4333-8444-555555555555' })
		;(window as unknown as { __fadenstackEngineSurface?: EngineSurface }).__fadenstackEngineSurface = surface
		surface.setTarget({ connectionId: original.id, provider: 'n8n', reference: 'flow1', sourceKind: 'saved', name: 'Flow 1', revision: 'd'.repeat(64), copySource: { reference: 'flow1', kind: 'saved', revision: 'd'.repeat(64) }, apiPrincipal: '11111111-2222-4333-8444-555555555555' })
		surface.recordResult({ connectionId: original.id, actionId: 'e'.repeat(32), generation: 0, operation: 'nc_n8n_draft', createdReference: 'flow2', createdName: 'Flow 2', provider: 'n8n' })
		surface.pause()
		const disconnect = vi.fn(async () => ({ ...original, connected: false, revision: 'b'.repeat(64) }))
		const api = markRaw({ engineConnections: async () => [{ ...original, connected: true }], engineWebhooks: async () => [], engineDisconnect: disconnect })
		const root = document.createElement('div'); document.body.append(root)
		const app = createApp(EngineConnections, { api: api as never })
		try {
			app.mount(root)
			const button = (name: string) => [...root.querySelectorAll('button')].find(b => b.textContent?.trim() === name)
			await vi.waitFor(() => expect(button('Disconnect')?.disabled).toBe(false))
			button('Disconnect')!.click()
			await vi.waitFor(() => expect(disconnect).toHaveBeenCalledOnce())
			expect(surface.state.target).toBeUndefined()
			expect(surface.state.lastResult).toBeUndefined()
			expect(surface.state.paused).toBe(false)
			expect(surface.state.connectionError).toContain('disconnected')
		} finally {
			delete (window as unknown as { __fadenstackEngineSurface?: EngineSurface }).__fadenstackEngineSurface
			surface.unbind(); app.unmount(); root.remove(); vi.unstubAllGlobals()
		}
	})
})
