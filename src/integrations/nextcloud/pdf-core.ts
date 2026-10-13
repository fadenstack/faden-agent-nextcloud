import type { getDocument } from 'pdfjs-dist'

/** Shared bounded extraction, using the maintained PDF.js entry for each platform. */
export async function extractPdfWith(load: typeof getDocument, bytes: Uint8Array, signal: AbortSignal) {
	if (bytes.byteLength > 10 * 1024 * 1024) throw new Error('PDF files must be at most 10 MiB.')
	signal.throwIfAborted()
	const task = load({ data: bytes, useSystemFonts: true })
	const stop = () => { void task.destroy() }
	const timeout = setTimeout(stop, 30000)
	signal.addEventListener('abort', stop, { once: true })
	try {
		const pdf = await task.promise
		let text = ''
		let processedPages = 0
		let cut = false
		const pages: { page: number; text: string }[] = []
		for (let page = 1; page <= Math.min(pdf.numPages, 50); page++) {
			signal.throwIfAborted()
			const p = await pdf.getPage(page)
			const content = await p.getTextContent()
			let pageText = ''
			for (const item of content.items) {
				if ('str' in item) pageText += item.str + (item.hasEOL ? '\n' : ' ')
				if (pageText.length >= 50001 - text.length) { cut = true; break }
			}
			const header = `\n[Page ${page}]\n`
			pages.push({ page, text: pageText.slice(0, Math.max(0, 50000 - text.length - header.length)) })
			text += header + pageText
			processedPages = page
			p.cleanup()
			if (text.length > 50000) { text = text.slice(0, 50000); cut = true; break }
		}
		if (!pages.some(page => page.text.trim())) throw new Error('This PDF has no extractable text. Scans need OCR, which is not supported.')
		return { text, pages, partial: cut || processedPages < pdf.numPages, processedPages, totalPages: pdf.numPages }
	} catch (error) {
		if (error instanceof Error && error.name === 'PasswordException') throw new Error('Encrypted PDFs are unsupported. Use an unencrypted text document.')
		throw error
	} finally {
		clearTimeout(timeout)
		signal.removeEventListener('abort', stop)
		await task.destroy()
	}
}
