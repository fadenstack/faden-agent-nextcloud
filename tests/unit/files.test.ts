import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { NextcloudFiles } from '../../src/integrations/nextcloud/files.ts'
import type { Identity } from '../../src/integrations/nextcloud/types.ts'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), request: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => `/index.php${path}`, generateRemoteUrl: (path: string) => `/remote.php/${path}` }))

const identity: Identity = { userId: 'alice', binding: 'binding', enabled: true, serverUrl: 'https://faden.test', agent: 'nc', development: false }
const marks = { key: 'fadenstack-provenance', metadata: '{"contract":"provenance/v1"}', frontMatter: '---\nai-generated: true\n---\n' }
const file = { id: 3, name: 'Summary.md', path: '/Summary.md', mime: 'text/markdown', size: 1, etag: 'e', folder: false, permissions: 31, url: '' }

describe('The commit carries the AI mark to FileService', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.mocked(axios.get).mockResolvedValue({ data: identity })
	})

	it('an update sends the marked text and the signed record with the commit', async () => {
		vi.mocked(axios.post).mockResolvedValueOnce({ data: { operation: 'update', file, provenance: marks } })
		const result = await new NextcloudFiles(identity).commit('p1', undefined, { content: 'marked', provenance: '{"record":{}}' })
		expect(vi.mocked(axios.post).mock.calls[0]!.slice(0, 2)).toEqual(['/index.php/apps/fadenstack_agent/proposals/p1/commit', { createProtocol: 1, content: 'marked', provenance: '{"record":{}}' }])
		expect(result.provenance).toEqual(marks)
	})
	it('a creation writes the text FileService accepted, then finishes with the read-back', async () => {
		vi.mocked(axios.post)
			.mockResolvedValueOnce({ data: { kind: 'dav-create', receiptId: 'r1', dav_path: '/files/alice/Summary.md', parent_resource_id: 'nextcloud:files:1', parent_path: '/', parent_etag: 'pe', content: 'accepted text' } })
			.mockResolvedValueOnce({ data: [{ ...file, id: 1, folder: true, path: '/', etag: 'pe', permissions: 31 }] })
			.mockResolvedValueOnce({ data: { operation: 'create', file, provenance: marks } })
		vi.mocked(axios.put).mockResolvedValueOnce({ status: 201 })
		const result = await new NextcloudFiles(identity).commit('p1', undefined, { content: 'offered text' })
		expect(vi.mocked(axios.post).mock.calls[0]![1]).toEqual({ createProtocol: 1, content: 'offered text' })
		expect(vi.mocked(axios.put).mock.calls[0]!.slice(0, 2)).toEqual(['/remote.php/dav/files/alice/Summary.md', 'accepted text'])
		expect(vi.mocked(axios.post).mock.calls[2]!.slice(0, 2)).toEqual(['/index.php/apps/fadenstack_agent/creations/finish', { receiptId: 'r1' }])
		expect(result.provenance).toEqual(marks)
	})
	it('without a mark the commit is as before', async () => {
		vi.mocked(axios.post).mockResolvedValueOnce({ data: { operation: 'update', file } })
		await new NextcloudFiles(identity).commit('p2')
		expect(vi.mocked(axios.post).mock.calls.at(-1)![1]).toEqual({ createProtocol: 1 })
	})
})
