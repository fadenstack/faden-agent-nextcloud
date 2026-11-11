import { describe, expect, it } from 'vitest'
import { frontMatterMark, withFrontMatter } from '@fadenstack/provenance'
import { nativeProvenanceSummary, provenanceNote } from '../../src/integrations/nextcloud/provenance.ts'
import { writeArguments } from '../../src/integrations/nextcloud/tools.ts'
import vectors from '../fixtures/provenance/front-matter.json'

describe('Shared front-matter format', () => {
 for (const vector of vectors.vectors) it(vector.name, () => {
  expect(withFrontMatter(vector.input, vectors.recordId)).toBe(vector.marked)
 })
})
describe('Truthful native mark summaries', () => {
 const id = '5a0e2b5c-7f3e-4a51-9a40-6a1c55e1d3f1'
 const front = withFrontMatter('Draft', id)
 const metadata = JSON.stringify({ record: { id } })
 it('counts only matching native readback, including front matter when metadata storage failed', () => {
  expect(frontMatterMark(front)?.recordId).toBe(id)
  expect(provenanceNote(nativeProvenanceSummary({ key: '', metadata, frontMatter: front, recordId: id }))).toBe('metadata-and-front-matter')
  expect(provenanceNote(nativeProvenanceSummary({ key: '', metadata: null, frontMatter: front, recordId: id }))).toBe('front-matter')
  expect(provenanceNote(nativeProvenanceSummary({ key: '', metadata: null, frontMatter: null, recordId: id }))).toBe('not-kept')
 })
 it('does not count a different front-matter record as the current placement', () => {
  expect(nativeProvenanceSummary({ key: '', metadata, frontMatter: withFrontMatter('Draft', 'other'), recordId: id }).marks).toEqual(['nextcloud-metadata'])
 })
 it('keeps explicit exemptions and rejects unsupported edit kinds', () => {
  expect(provenanceNote(nativeProvenanceSummary({ key: '', metadata: null, frontMatter: null, exempt: 'edit-kind' }))).toBe('exempt')
  expect(writeArguments('nc_create_text_file', { filename: 'New.md', content: 'Text', edit_kind: 'translated' }).editKind).toBe('translated')
  expect(() => writeArguments('nc_create_text_file', { filename: 'New.md', content: 'Text', edit_kind: 'bypass' })).toThrow('edit kind')
 })
})
