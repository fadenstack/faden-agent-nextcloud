import { Carriers } from '@fadenstack/provenance'
import type { FileMarks } from './types.ts'

export const FILE_METADATA = 'nextcloud-metadata'
export interface ProvenanceSummary {
	record_id: string | null
	marks: string[]
	exempt: string | null
	missing: { layer: string, method: string, reason: string | null }[]
}
export type ProvenanceNoteKind = 'metadata-and-front-matter' | 'metadata' | 'front-matter' | 'unsigned' | 'not-kept' | 'exempt'

export function provenanceNote(summary: ProvenanceSummary): ProvenanceNoteKind {
	if (summary.exempt) {
		return 'exempt'
	}
	if (!summary.record_id) {
		return 'unsigned'
	}
	const metadata = summary.marks.includes(FILE_METADATA)
	const frontMatter = summary.marks.includes(Carriers.frontMatter)
	return metadata && frontMatter ? 'metadata-and-front-matter' : metadata ? 'metadata' : frontMatter ? 'front-matter' : 'not-kept'
}

/** Native-prepared marks, checked against the native save readback, never model assertions. */
export function nativeProvenanceSummary(marks: FileMarks): ProvenanceSummary {
	let id: string | null = null
	try {
		id = JSON.parse(marks.metadata ?? 'null')?.record?.id ?? null
	} catch { /* unverified */ }
	const front = /(?:^|\n)ai-record-id: "([^"]+)"(?:\r?\n|$)/.exec(marks.frontMatter ?? '')?.[1]
	const record = marks.recordId ?? id
	return {
		record_id: record ?? null,
		marks: [...(id && id === record ? [FILE_METADATA] : []), ...(front && front === record ? [Carriers.frontMatter] : [])],
		exempt: marks.exempt ?? null,
		missing: [],
	}
}
