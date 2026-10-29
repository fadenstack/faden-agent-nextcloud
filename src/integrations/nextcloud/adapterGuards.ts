import type { Identity } from './types.ts'

/** Expanded adapters have no production native scope/journal contract yet. */
export function experimentalAdaptersAllowed(config: Identity): boolean {
	return config.development === true && !config.scopePolicy
}

export function positiveInteger(value: unknown, label: string): number {
	if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < 1) throw new Error(`A positive safe integer ${label} is required.`)
	return value
}

export function optionalPositiveInteger(value: unknown, label: string): number | undefined {
	return value === undefined ? undefined : positiveInteger(value, label)
}

/** Model arguments must keep their declared types; coercion can change an effect. */
export function stringArgument(value: unknown, label: string): string {
	if (value === undefined) return ''
	if (typeof value !== 'string') throw new Error(`A string ${label} is required.`)
	return value
}

export function optionalBoolean(value: unknown, label: string): boolean | undefined {
	if (value === undefined) return undefined
	if (typeof value !== 'boolean') throw new Error(`A boolean ${label} is required.`)
	return value
}

/** Bound nested provider payloads before any preview or dispatch. */
export function jsonRecord(value: unknown, label: string): Record<string, unknown> {
	if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error(`A structured ${label} object is required.`)
	let nodes = 0
	const visit = (item: unknown, depth: number): void => {
		if (++nodes > 2000 || depth > 8) throw new Error(`${label} is too complex.`)
		if (item === null || typeof item === 'string' || typeof item === 'boolean' || (typeof item === 'number' && Number.isFinite(item))) return
		if (typeof item !== 'object' || (!Array.isArray(item) && ![Object.prototype, null].includes(Object.getPrototypeOf(item)))) throw new Error(`${label} must contain only JSON data.`)
		for (const child of Object.values(item)) visit(child, depth + 1)
	}
	visit(value, 0)
	if (new TextEncoder().encode(JSON.stringify(value)).byteLength > 65536) throw new Error(`${label} exceeds 65,536 bytes.`)
	return value as Record<string, unknown>
}

/** Encode exactly one raw DAV identifier; URL dot segments are never identifiers. */
export function davSegment(value: string): string {
	if (!value || value.length > 1024 || value === '.' || value === '..' || /[\x00-\x1f\x7f/\\]/.test(value)) throw new Error('A valid DAV path identifier is required.')
	return encodeURIComponent(value)
}

export function davFilePath(value: string): string {
	if (typeof value !== 'string' || /[\x00-\x1f\x7f\\]/.test(value) || /^[A-Za-z][A-Za-z0-9+.-]*:/.test(value)) throw new Error('A native file path is required.')
	return value.replace(/^\/+|\/+$/g, '').split('/').filter((part, index, parts) => part || parts.length !== 1).map(davSegment).join('/')
}

/** If-Match must name one exact strong revision, never a wildcard or list. */
export function strongEtagHeader(value: string): string {
	if (typeof value !== 'string') throw new Error('An exact strong ETag revision is required.')
	const raw = value.startsWith('"') && value.endsWith('"') ? value.slice(1, -1) : value
	if (!raw || raw.length > 150 || raw === '*' || raw.startsWith('W/') || /[\x00-\x20\x7f",]/.test(raw)) throw new Error('An exact strong ETag revision is required.')
	return `"${raw}"`
}
