import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist'
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
import { extractPdfWith } from './pdf-core.ts'

GlobalWorkerOptions.workerSrc = workerUrl

/** Mozilla's maintained extractor; no custom PDF parser or external document service. */
export async function extractPdf(bytes: Uint8Array, signal: AbortSignal) {
	return extractPdfWith(getDocument, bytes, signal)
}
