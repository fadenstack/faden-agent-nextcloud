import { Carriers, sidecarDocument, type ContentMarker, type MarkableContent, type MarkOutcome, type PlacedContent, type Placement, type ProvenanceRecord, type SinkInfo } from '@fadenstack/provenance'
import type { FileMarks, WriteResult } from './types.ts'

/** Where the agent's file writes land, as placement reports name it. */
export const FILE_SINK = 'nextcloud.file'
/** Nextcloud's file metadata as a carrier, and the marker that hands the signed record to it. */
export const FILE_METADATA = 'nextcloud-metadata'
/** The metadata key FileService keeps the record under (`nc:metadata-fadenstack-provenance` over WebDAV). */
export const METADATA_KEY = 'fadenstack-provenance'

/** The media type of a file the agent writes: Markdown for `.md`, plain text for `.txt` (as FileService has it). */
export function mediaTypeOf(filename: string): 'text/markdown' | 'text/plain' {
	return /\.md$/i.test(filename) ? 'text/markdown' : 'text/plain'
}

/** The signed record on its way to FileService with the write, and what FileService read back after it. */
export interface FileRecordCarrier {
	/** Hands the placement's signed record (the SDK's sidecar document, as JSON) to the write. */
	hand(document: string): void
	/** The record the file's metadata held after the write; null for none, undefined where it was not read back. */
	stored(): string | null | undefined
}

/** A file write as a sink: it carries Nextcloud's file metadata, and a Markdown file its front matter. */
export interface NextcloudFileSink extends SinkInfo {
	readonly fileRecord: FileRecordCarrier
}

function isFileSink(sink: SinkInfo): sink is NextcloudFileSink {
	return sink.kind === FILE_SINK && typeof (sink as Partial<NextcloudFileSink>).fileRecord?.hand === 'function'
}

/** The record's id in a sidecar document, or undefined. */
function recordIdOf(document: string): string | undefined {
	try {
		const id = (JSON.parse(document) as { record?: { id?: unknown } }).record?.id
		return typeof id === 'string' ? id : undefined
	} catch { return undefined }
}

/**
 * The mark Nextcloud itself keeps: the placement's signed record, handed to the write (`prepare`) and stored by
 * FileService as the file's metadata `fadenstack-provenance`, then checked in what FileService read back after the
 * write (`attach`). Server-side metadata stays with the file through renames, moves, shares, new versions, Text's
 * saves and the trash, and the app's copy listener puts it on copies.
 */
export class NextcloudFileMarker implements ContentMarker {

	readonly name = FILE_METADATA
	readonly layer = 'metadata'

	supports(sink: SinkInfo): boolean {
		return isFileSink(sink) && sink.carries.includes(FILE_METADATA)
	}

	prepare(content: MarkableContent, record: ProvenanceRecord): MarkableContent {
		if (isFileSink(content.sink)) content.sink.fileRecord.hand(JSON.stringify(sidecarDocument(record)))
		return content
	}

	attach(placed: PlacedContent, record: ProvenanceRecord): MarkOutcome {
		const stored = isFileSink(placed.sink) ? placed.sink.fileRecord.stored() : undefined
		if (stored === undefined) return { applied: true, method: this.name, verified: false, detail: 'not read back' }
		return stored !== null && recordIdOf(stored) === record.id
			? { applied: true, method: this.name, verified: true }
			: { applied: false, method: this.name, reason: 'stripped-by-host', detail: 'the file metadata does not hold the record' }
	}

}

/**
 * One file write's sink: the file's metadata always (a file is a container: it needs a mark of its own besides the
 * model's watermark), and the front matter of a Markdown file, which the SDK's front matter marker writes and checks
 * in the front matter FileService read back.
 */
export class FileWrite {

	readonly mediaType: 'text/markdown' | 'text/plain'
	readonly sink: NextcloudFileSink
	#record: string | undefined
	#marks: FileMarks | undefined

	constructor(filename: string) {
		this.mediaType = mediaTypeOf(filename)
		this.sink = {
			kind: FILE_SINK,
			container: true,
			carries: this.mediaType === 'text/markdown' ? [FILE_METADATA, Carriers.frontMatter] : [FILE_METADATA],
			fileRecord: {
				hand: (document) => { this.#record = document },
				stored: () => this.#marks?.metadata,
			},
			readBack: () => this.#marks ? this.#marks.frontMatter ?? '' : undefined,
		}
	}

	/** The signed record a marker handed to this write; undefined when there is none (exempt, or the server did not sign). */
	get record(): string | undefined { return this.#record }

	/** Keeps what FileService read back after the write, for the markers' checks. */
	written(result: WriteResult): WriteResult {
		this.#marks = result.provenance
		return result
	}

}

/** What the model and the panel are told about a write's AI mark: ids, marks and reasons, never content. */
export interface ProvenanceSummary {
	/** The signed record's id, in the file's metadata (and a Markdown file's front matter); null without one. */
	record_id: string | null
	/** The marks the file holds, read back after the write: `nextcloud-metadata`, `front-matter`. */
	marks: string[]
	/** Why the content needed no mark (`edit-kind`: a translation or a correction; `label`; ...), or null. */
	exempt: string | null
	/** Marks it needs that did not hold, and why. */
	missing: { layer: string; method: string; reason: string | null }[]
}

export function provenanceSummary(placement: Placement<unknown>): ProvenanceSummary {
	return {
		record_id: placement.recordId ?? null,
		marks: placement.layers.filter(l => l.layer === 'metadata' && l.applied && [FILE_METADATA, Carriers.frontMatter].includes(l.method)).map(l => l.method),
		exempt: placement.exemptBecause ?? null,
		missing: placement.missing.map(m => ({ layer: m.layer, method: m.method, reason: m.reason ?? null })),
	}
}

/** Which line the panel shows under a write (ProvenanceNote.vue). */
export type ProvenanceNoteKind = 'metadata-and-front-matter' | 'metadata' | 'front-matter' | 'unsigned' | 'not-kept' | 'exempt'

export function provenanceNote(summary: ProvenanceSummary): ProvenanceNoteKind {
	if (summary.exempt) return 'exempt'
	if (!summary.record_id) return 'unsigned'
	const metadata = summary.marks.includes(FILE_METADATA)
	const frontMatter = summary.marks.includes(Carriers.frontMatter)
	return metadata && frontMatter ? 'metadata-and-front-matter' : metadata ? 'metadata' : frontMatter ? 'front-matter' : 'not-kept'
}
