import { describe, expect, it, vi } from 'vitest'
import { findPassages } from '../../src/integrations/nextcloud/passages.ts'
import { boundedResults, davResourceIds, NextcloudSearchService, resourceId } from '../../src/integrations/nextcloud/search.ts'
import type { FileApi, FileContext } from '../../src/integrations/nextcloud/types.ts'

const context: FileContext = { id: 'opaque', parentId: 1, expires: 9999999999,
	file: { id: 2, name: 'contract.md', path: '/Engineering/contract.md', mime: 'text/markdown', size: 100, etag: 'revision1', folder: false, permissions: 31, url: 'https://cloud.test/f/2' } }
describe('literal passages and trustworthy source locations', () => {
	it('finds case-insensitive phrases across whitespace and counts all occurrences', () => {
		const result = findPassages({ text: 'Quality\nassurance. QUALITY assurance.', partial: false }, 'quality assurance', 'exact_phrase', 1)
		expect(result.total_matches).toBe(2)
		expect(result.matches).toHaveLength(1)
		expect(result.matches_truncated).toBe(true)
		expect(result.matches[0]?.line).toBe(1)
	})
	it('uses genuine page records and does not mistake document markers for PDF pages', () => {
		const result = findPassages({ text: '', pages: [{ page: 7, text: '[Page 999] ISO 9001' }], partial: false }, 'ISO 9001')
		expect(result.matches[0]?.page).toBe(7)
		const text = findPassages({ text: '[Page 999] ISO 9001', partial: false }, 'ISO 9001')
		expect(text.matches[0]?.page).toBeUndefined()
	})
	it('does not report a whole-document total for partial extraction or accept regex syntax', () => {
		const result = findPassages({ text: 'ISO 9001 (a+b)', partial: true }, '(a+b)')
		expect(result.total_matches).toBeNull()
		expect(result.processed_match_count).toBe(1)
		expect(findPassages({text:'aaaa',partial:false},'(a+)+').matches).toHaveLength(0)
	})
	it('bounds matching and rejects empty input', () => {
		expect(() => findPassages({text:'',partial:false},'')).toThrow()
		expect(() => findPassages({text:'',partial:false},'x','terms',51)).toThrow()
	})
})
describe('authorized discovery and cache boundaries', () => {
	it('bounds multilingual metadata by UTF-8 bytes without splitting a file result', () => {
		const bounded = boundedResults([{ name: 'ü'.repeat(20000) }, { name: 'ü'.repeat(20000) }])
		expect(bounded.rows).toHaveLength(1)
		expect(bounded.truncated).toBe(true)
	})
	it('rejects foreign references and XML entities', () => {
		expect(() => resourceId('https://cloud.test/f/2')).toThrow()
		expect(() => resourceId('nextcloud:files:2/../../3')).toThrow()
		expect(() => davResourceIds('<!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/passwd">]><x/>')).toThrow()
	})
	it('reauthorizes cache hits, invalidates revisions and refuses revoked content', async () => {
		let current = context.file
		const resolve = vi.fn(async () => [current])
		const files = { assertIdentity: vi.fn(async () => {}), select: vi.fn(async () => ({ ...context, file: current })),
			searchTransport: { resolve } } as unknown as FileApi
		const extract = vi.fn(async () => ({ text: 'Termination: 30 days.', partial: false }))
		const service = new NextcloudSearchService(files, extract)
		service.remember(current)
		const signal = new AbortController().signal
		await service.inFile('nextcloud:files:2','termination','exact_phrase',10,signal)
		const cached = await service.inFile('nextcloud:files:2','termination','terms',10,signal)
		expect(cached.cache_hit).toBe(true)
		expect(extract).toHaveBeenCalledTimes(1)
		expect(resolve).toHaveBeenLastCalledWith(['nextcloud:files:2'],'all',signal,true)
		current = { ...current, etag: 'revision2' }
		await service.inFile('nextcloud:files:2','termination','terms',10,signal)
		expect(extract).toHaveBeenCalledTimes(2)
		resolve.mockResolvedValue([])
		await expect(service.inFile('nextcloud:files:2','termination','terms',10,signal)).rejects.toThrow('revoked')
		expect(service.cache.size).toBe(0)
	})
	it('clears caches when Nextcloud identity changes and does not read unknown IDs', async () => {
		const files = {assertIdentity:vi.fn(async()=>{throw new Error('Session changed')}),select:vi.fn()} as unknown as FileApi
		const service = new NextcloudSearchService(files)
		service.remember(context.file)
		await expect(service.metadata('nextcloud:files:2',new AbortController().signal)).rejects.toThrow('Session changed')
		expect(service.issued.size).toBe(0)
		expect(files.select).not.toHaveBeenCalled()
	})
})
