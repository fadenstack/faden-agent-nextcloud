import { describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'
import ProvenanceNote from '../../src/components/ProvenanceNote.vue'
import { frontMatterMark, withFrontMatter } from '@fadenstack/provenance'
import { nativeProvenanceSummary, provenanceNote } from '../../src/integrations/nextcloud/provenance.ts'
import { writeArguments } from '../../src/integrations/nextcloud/tools.ts'
import vectors from '../fixtures/provenance/front-matter.json'
vi.mock('@nextcloud/l10n', () => ({ t: (_app: string, text: string) => text }))

describe('Shared front-matter format', () => {
 for (const vector of vectors.vectors) it(vector.name, () => {
  expect(withFrontMatter(vector.input, vectors.recordId)).toBe(vector.marked)
 })
})
describe('Truthful native mark summaries', () => {
 it('hides routine exemption notices by default and supports the shared SDK opt-in', async () => {
  const show = ref(false), target = document.createElement('div')
  const app = createApp({ render: () => h(ProvenanceNote, { summary: { record_id: null, marks: [], missing: [], exempt: 'edit-kind' }, showExempt: show.value }) })
  try {
   app.mount(target)
   expect(target.textContent).toBe('')
   show.value = true; await nextTick()
   expect(target.textContent).toContain('No new AI mark was required')
   show.value = false; await nextTick()
   expect(target.textContent).toBe('')
  } finally { app.unmount() }
 })
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
