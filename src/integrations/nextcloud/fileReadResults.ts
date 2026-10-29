import { generateUrl } from '@nextcloud/router'
import { fileReference } from '../../platform/scope.ts'
import type { FolderListing, FolderListingEntry } from './types.ts'

/** Only native canonical references may become actionable links; respect subpath/index.php deployments. */
export function nativeFileLink(reference: unknown): string | undefined {
	try {
		const id = fileReference(reference).slice('nextcloud:files:'.length)
		const url = new URL(generateUrl(`/f/${id}`), location.origin)
		return url.origin === location.origin && /^https?:$/.test(url.protocol) && !url.username && !url.password ? url.href : undefined
	} catch { return undefined }
}

function text(value: unknown, max: number): value is string { return typeof value === 'string' && value.length > 0 && value.length <= max && !value.includes('\0') }

/** Validate before lineage registration or rendering; never infer complete coverage from missing fields. */
export function folderReadResult(value: unknown, target: string): FolderListing {
	const fail = () => { throw new Error('Invalid native folder response.') }
	if (!value || typeof value !== 'object') return fail()
	const listing = value as Record<string, unknown>
	if (listing.resourceId !== target || !text(listing.etag, 150) || !text(listing.directory, 8192)
		|| typeof listing.partial !== 'boolean' || !Array.isArray(listing.entries) || listing.entries.length > 100) return fail()
	const seen = new Set<string>()
	const entries = listing.entries.map((entry): FolderListingEntry => {
		if (!entry || typeof entry !== 'object') return fail()
		const row = entry as Record<string, unknown>
		let reference: string
		try { reference = fileReference(row.resourceId) } catch { return fail() }
		if (reference === target || seen.has(reference) || !text(row.filename, 1024) || !text(row.etag, 150) || typeof row.folder !== 'boolean') return fail()
		seen.add(reference)
		if (row.size !== undefined && (typeof row.size !== 'number' || !Number.isFinite(row.size) || row.size < -1)) return fail()
		if (row.modified !== undefined && (typeof row.modified !== 'number' || !Number.isSafeInteger(row.modified) || row.modified < 0 || row.modified > 8640000000000)) return fail()
		if (row.permissions !== undefined && (!Number.isInteger(row.permissions) || Number(row.permissions) < 0 || Number(row.permissions) > 31)) return fail()
		return {
			resourceId: reference,
			filename: row.filename,
			etag: row.etag,
			folder: row.folder,
			href: nativeFileLink(reference) ?? '',
			...(row.size !== undefined ? { size: row.size as number } : {}),
			...(row.modified !== undefined ? { modified: row.modified as number } : {}),
			...(row.permissions !== undefined ? { permissions: row.permissions as number } : {}),
		}
	})
	return { resourceId: target, directory: listing.directory, etag: listing.etag, entries, partial: listing.partial }
}
