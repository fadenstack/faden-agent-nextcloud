export interface DocumentText { text: string, partial: boolean, pages?: { page: number, text: string }[] }
export interface Passage { excerpt: string, matched_text: string, page?: number, line?: number }

/** Literal matching only. Page numbers come from PDF.js records, never markers. */
export function findPassages(document: DocumentText, query: unknown, mode: unknown = 'exact_phrase', maximum: unknown = 10) {
	if (typeof query !== 'string' || !query.trim() || query.length > 200 || /[\x00-\x1f\x7f]/.test(query)) {
		throw new Error('Use a search phrase of 1–200 characters.')
	}
	if (mode !== 'exact_phrase' && mode !== 'terms') {
		throw new Error('Use exact_phrase or terms match mode.')
	}
	if (typeof maximum !== 'number' || !Number.isInteger(maximum) || maximum < 1 || maximum > 50) {
		throw new Error('Use a maximum of 1–50 matches.')
	}
	const escape = (value: string) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	const words = query.trim().split(/\s+/)
	if (words.length > 20) {
		throw new Error('Use at most 20 search terms.')
	}
	const pattern = mode === 'terms' ? [...new Set(words)].map(escape).join('|') : words.map(escape).join('\\s+')
	const matches: Passage[] = []
	let count = 0
	for (const unit of document.pages ?? [{ text: document.text, page: undefined }]) {
		const expression = new RegExp(pattern, 'giu')
		let match: RegExpExecArray | null
		while ((match = expression.exec(unit.text))) {
			count++
			if (matches.length >= maximum) {
				continue
			}
			const start = Math.max(0, match.index - 180)
			const end = Math.min(unit.text.length, match.index + match[0].length + 180)
			matches.push({
				excerpt: unit.text.slice(start, end),
				matched_text: match[0],
				...(unit.page ? { page: unit.page } : { line: unit.text.slice(0, match.index).split('\n').length }),
			})
		}
	}
	return {
		matches,
		processed_match_count: count,
		total_matches: document.partial ? null : count,
		partial: document.partial,
		matches_truncated: count > maximum,
	}
}
