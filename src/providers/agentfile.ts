import type { AgentfileViewResults } from '../integrations/nextcloud/types.ts'

/** Bounded UI data only. No imported field is applied to agent configuration. */
export function agentfileView(value: unknown): AgentfileViewResults {
	if (!value || typeof value !== 'object' || Array.isArray(value)) {
		throw new Error('Invalid native agent definition response.')
	}
	const v = value as Record<string, unknown>
	const id = (value: unknown): string => {
		if (typeof value !== 'string' || !/^nextcloud:files:[1-9][0-9]{0,14}$/.test(value)) {
			throw new Error('Invalid native definition file reference.')
		}
		return value
	}
	const text = (value: unknown, max: number): string => {
		if (typeof value !== 'string' || value.length > max) {
			throw new Error('Invalid native definition text.')
		}
		return value
	}
	if (Array.isArray(v.definitions)) {
		if (v.definitions.length > 8) {
			throw new Error('Too many native definitions.')
		}
		return { kind: 'nextcloud-agentfile-result', name: 'Agent definitions', sources: v.definitions.map((row) => ({ name: text(row.displayName, 200), available: true, resourceId: id(row.resourceId) })) }
	}
	if (v.untrustedConfigurationData !== true || typeof v.digest !== 'string' || !/^[a-f0-9]{64}$/.test(v.digest)
		|| !v.manifest || typeof v.manifest !== 'object' || Array.isArray(v.manifest)) { throw new Error('Invalid native definition response.') }
	const manifest = v.manifest as Record<string, unknown>
	if (manifest.version !== 'v1.9') {
		throw new Error('Unsupported native definition version.')
	}
	const sources = v.source ? [v.source] : v.sources
	if (!Array.isArray(sources) || sources.length > 5) {
		throw new Error('Invalid native definition sources.')
	}
	return {
		kind: 'nextcloud-agentfile-result',
		name: text(manifest.name, 200),
		description: text(manifest.description, 2000),
		instructions: text(manifest.instructions, 16000),
		resourceId: id(v.definitionResourceId),
		sources: sources.map((source) => {
			if (!source || typeof source !== 'object' || Array.isArray(source)) {
				throw new Error('Invalid native source metadata.')
			}
			const row = source as Record<string, unknown>
			const available = v.source ? row.untrustedDocumentData === true : row.available === true
			return { name: available ? text(row.name, 510) : 'Source unavailable', available, ...(available ? { resourceId: id(row.resourceId) } : {}) }
		}),
	}
}
