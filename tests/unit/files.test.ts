import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { NextcloudFiles, NextcloudRequestError, requestErrorName } from '../../src/integrations/nextcloud/files.ts'
import type { Identity } from '../../src/integrations/nextcloud/types.ts'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), request: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => `/index.php${path}`, generateRemoteUrl: (path: string) => `/remote.php/${path}` }))
const identity: Identity = { userId: 'alice', binding: 'binding', enabled: true, serverUrl: 'https://faden.test', agent: 'nc', development: false }
describe('Native marked file protocol', () => {
 beforeEach(() => vi.clearAllMocks())
 it('refuses a late content or record change before sending a commit', async () => {
  await expect(new NextcloudFiles(identity).commit('p1', undefined, { content: 'changed' })).rejects.toThrow('before approval')
  expect(axios.post).not.toHaveBeenCalled()
 })
 it('commits only the approved proposal and preserves native mark readback', async () => {
  const provenance = { key: 'fadenstack-provenance', metadata: null, frontMatter: null }
  vi.mocked(axios.post).mockResolvedValueOnce({ data: { operation: 'update', provenance } })
  expect((await new NextcloudFiles(identity).commit('p1')).provenance).toEqual(provenance)
  expect(vi.mocked(axios.post).mock.calls[0]![1]).toEqual({ createProtocol: 1 })
 })
 it('requires the signed-in cloud identity for marking even with History off', async () => {
  const api = new NextcloudFiles({ ...identity, fileProvenanceEnabled: true })
  await expect(api.propose('context', { operation: 'create', filename: 'New.md', content: 'Draft' })).rejects.toThrow()
  expect(axios.post).not.toHaveBeenCalled()
 })
 it('keeps native locked and conflict errors meaningful without treating timeouts as responses', async () => {
  const locked = Object.assign(new Error('Request failed'), { response: { status: 423, data: { message: 'The file is busy. Try again.' } } })
  vi.mocked(axios.post).mockRejectedValueOnce(locked)
  await expect(new NextcloudFiles(identity).commit('p1')).rejects.toMatchObject({ name: 'NextcloudLocked', message: 'The file is busy. Try again.' })
  const offline = new Error('Network Error')
  vi.mocked(axios.post).mockRejectedValueOnce(offline)
  await expect(new NextcloudFiles(identity).read('c1')).rejects.toBe(offline)
  expect(requestErrorName(409)).toBe('NextcloudConflict')
  expect(new NextcloudRequestError(403, 'Denied').name).toBe('NextcloudForbidden')
 })
})
