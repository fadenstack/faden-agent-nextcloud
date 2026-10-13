import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist'
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url'

GlobalWorkerOptions.workerSrc = workerUrl

/** Mozilla's maintained extractor; no custom PDF parser or external document service. */
export async function extractPdf(bytes: Uint8Array, signal: AbortSignal) {
	if (bytes.byteLength > 10 * 1024 * 1024) throw new Error('PDF files must be at most 10 MiB.')
	signal.throwIfAborted()
	const task = getDocument({ data: bytes, useSystemFonts: true })
	const stop = () => { void task.destroy() }
	const timeout = setTimeout(stop, 30000)
	signal.addEventListener('abort', stop, { once: true })
	try {
		const pdf = await task.promise
		let text = ''
		let processedPages = 0
		let cut = false
		for (let page = 1; page <= Math.min(pdf.numPages, 50); page++) {
			signal.throwIfAborted()
			const p = await pdf.getPage(page)
			const content = await p.getTextContent()
			let pageText = ''
			for (const item of content.items) {
				if ('str' in item) pageText += item.str + (item.hasEOL ? '\n' : ' ')
				if (pageText.length >= 50001 - text.length) { cut = true; break }
			}
			text += `\n[Page ${page}]\n${pageText}`
			processedPages = page
			p.cleanup()
			if (text.length > 50000) { text = text.slice(0, 50000); cut = true; break }
		}
		if (!text.replace(/\[Page \d+\]/g, '').trim()) throw new Error('This PDF has no extractable text. Scans need OCR, which is not supported.')
		return { text, partial: cut || processedPages < pdf.numPages, processedPages, totalPages: pdf.numPages }
	} finally {
		clearTimeout(timeout)
		signal.removeEventListener('abort', stop)
		await task.destroy()
	}
}
