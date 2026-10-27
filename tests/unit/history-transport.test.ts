import { afterEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { NextcloudFiles } from '../../src/integrations/nextcloud/files.ts'
import type { HistoryPreview, Identity } from '../../src/integrations/nextcloud/types.ts'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => path, generateRemoteUrl: () => '/remote.php/dav' }))
vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'native-csrf-token' }))
const identity: Identity = { userId: 'alice', binding: 'native-session', serverUrl: 'https://faden.test', agent: 'agent', enabled: true, development: false, historyEnabled: true }
const preview: HistoryPreview = { id: 'a'.repeat(48), digest: 'b'.repeat(64), actionId: 'c'.repeat(32), direction: 'undo', before: 'Generated', content: 'Original', target: '/draft.md', expires: 9999999999 }
function fixture() {
	vi.mocked(axios.get).mockResolvedValue({ data: identity })
	const files = new NextcloudFiles(identity)
	const provider = vi.fn(async () => 'synthetic-ephemeral-device-token')
	files.setCloudIdentity(provider)
	const fetcher = vi.fn(async () => new Response(JSON.stringify([]), { status: 200 }))
	vi.stubGlobal('fetch', fetcher)
	return { files, provider, fetcher }
}
afterEach(() => { vi.unstubAllGlobals(); vi.clearAllMocks() })
describe('native cloud-token transport', () => {
	it('sends the ephemeral token only in a same-origin request header, with native CSRF and redirect refusal', async () => {
		const { files, provider, fetcher } = fixture()
		const signal = new AbortController().signal
		await files.history.prepare(preview.actionId, 'undo', signal)
		expect(provider).toHaveBeenCalledWith(signal)
		expect(fetcher).toHaveBeenCalledWith('/apps/fadenstack_agent/history/prepare', expect.objectContaining({
			credentials: 'same-origin', redirect: 'error', cache: 'no-store',
			headers: expect.objectContaining({ 'X-Fadenstack-Agent-Token': 'synthetic-ephemeral-device-token', 'X-Fadenstack-Session': identity.binding, requesttoken: 'native-csrf-token' }),
			body: JSON.stringify({ actionId: preview.actionId, direction: 'undo' }),
		}))
	})
	it('rejects changed native identity/configuration before obtaining a token', async () => {
		const { files, provider, fetcher } = fixture()
		vi.mocked(axios.get).mockResolvedValue({ data: { ...identity, historyEnabled: false } })
		await expect(files.history.list(new AbortController().signal)).rejects.toThrow('configuration changed')
		expect(provider).not.toHaveBeenCalled()
		expect(fetcher).not.toHaveBeenCalled()
	})
	it('clears its callback at sign-out and never stores a reusable bearer token', async () => {
		const { files, provider, fetcher } = fixture()
		files.setCloudIdentity()
		await expect(files.history.execute(preview, new AbortController().signal)).rejects.toThrow('Sign in')
		expect(provider).not.toHaveBeenCalled()
		expect(fetcher).not.toHaveBeenCalled()
	})
	it('rejects a preview path injection and an already-stopped call before native or token requests', async () => {
		const { files, provider, fetcher } = fixture()
		await expect(files.history.approve({ ...preview, id: '../../other-app' }, new AbortController().signal)).rejects.toThrow('Invalid history preview')
		await expect(files.history.list(AbortSignal.abort())).rejects.toThrow()
		expect(axios.get).not.toHaveBeenCalled()
		expect(provider).not.toHaveBeenCalled()
		expect(fetcher).not.toHaveBeenCalled()
	})
	it('preserves conflict status for workspace outcomes without attaching request headers to errors', async () => {
		const { files, fetcher } = fixture()
		fetcher.mockResolvedValue(new Response(JSON.stringify({ message: 'The file changed.' }), { status: 409 }))
		await expect(files.history.execute(preview, new AbortController().signal)).rejects.toMatchObject({ message: 'The file changed.', response: { status: 409 } })
	})
})
