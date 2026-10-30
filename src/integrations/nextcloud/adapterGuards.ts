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

/** Response validation for experimental adapters, never a native effect-boundary proof. */
export function assertPrototypeAcknowledgement(name: string, value: unknown, args: Readonly<Record<string, unknown>>): void {
	const result = value && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {}
	const id = (key: string) => typeof result[key] === 'number' && Number.isSafeInteger(result[key]) && (result[key] as number) > 0
	const text = (key: string) => typeof result[key] === 'string' && (result[key] as string).trim().length > 0
	let confirmed = false
	switch (name) {
	case 'nc_mail_save_draft': confirmed = id('draftId') && text('revision'); break
	case 'nc_mail_send_draft': confirmed = result.delivered === true && text('messageId'); break
	case 'nc_talk_send_message': confirmed = id('messageId') && typeof result.timestamp === 'number' && Number.isFinite(result.timestamp) && result.timestamp >= 0; break
	case 'nc_collectives_create_page': confirmed = id('pageId') && text('title'); break
	case 'nc_collectives_delete_page': confirmed = result.deleted === true && result.pageId === args.pageId; break
	case 'nc_forms_create_form': confirmed = id('formId') && text('title'); break
	case 'nc_forms_submit_response': confirmed = result.recorded === true && id('submissionId'); break
	case 'nc_whiteboard_create': confirmed = result.created === true && text('path'); break
	case 'nc_flow_create_draft': confirmed = id('ruleId') && result.active === false; break
	case 'nc_flow_update_rule': confirmed = result.ruleId === args.ruleId && (args.deleteRule === true ? result.deleted === true : result.deleted === false && result.active === args.active); break
	case 'nc_windmill_draft': confirmed = result.draft === true && result.path === args.path; break
	case 'nc_windmill_run': confirmed = text('jobId') && text('status'); break
	case 'nc_n8n_draft': confirmed = text('id') && result.active === false; break
	case 'nc_n8n_execute': confirmed = text('executionId') && text('status'); break
	case 'nc_notes_create': confirmed = id('noteId') && text('title'); break
	case 'nc_bookmarks_create': confirmed = id('bookmarkId') && text('url'); break
	}
	if (!confirmed) throw new Error('The service did not confirm the requested action. Inspect the resource before another attempt.')
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
