import { fileCapability, stricterRisk, type NextcloudToolName, type ToolRisk, type ToolSubsystem } from '../platform/catalog.ts'
import type { PublishedTool } from '../platform/scope.ts'

export type ProviderId = ToolSubsystem
export type ConflictMode = 'conditional' | 'native' | 'read-only'
export type HistorySupport = 'certified' | 'audit-only' | 'not-applicable'
export type RefreshStrategy = 'native-event' | 'route' | 'child-reload' | 'manual'

/** Authenticated native discovery, never a manifest or an MCP annotation. */
export interface ProviderCapability {
	readonly provider: ProviderId
	readonly apiVersion: string
	readonly policyVersion: string
	readonly enabled: boolean
	readonly tools: readonly NextcloudToolName[]
	readonly conflict: ConflictMode
	readonly history: HistorySupport
	readonly refresh: RefreshStrategy
	readonly canRead: boolean
	readonly canWrite: boolean
	/** Native facade: Calendar discovery is backed only by configured VTODO lists. */
	readonly discoveryOnly?: boolean
}

/** The implementation must exist independently of the discovered advertisement. */
export interface ProviderImplementation {
	readonly provider: ProviderId
	readonly apiVersions: readonly string[]
	readonly tools: readonly NextcloudToolName[]
	readonly certifiedHistoryTools?: readonly NextcloudToolName[]
}

export interface AvailableProviderTool {
	readonly name: NextcloudToolName
	readonly provider: ProviderId
	readonly risk: ToolRisk
	readonly individualApproval: boolean
	readonly capability: Readonly<ProviderCapability>
}

/** Intersection only: this registry cannot publish, grant or lower a tool's risk. */
export class ProviderRegistry {

	readonly #implementations = new Map<ProviderId, Readonly<ProviderImplementation>>()

	constructor(implementations: readonly ProviderImplementation[]) {
		for (const implementation of implementations) {
			if (this.#implementations.has(implementation.provider) || !implementation.apiVersions.length
				|| implementation.apiVersions.some(version => !version || version.length > 100)
				|| new Set(implementation.tools).size !== implementation.tools.length
				|| implementation.tools.some(name => fileCapability(name)?.subsystem !== implementation.provider)
				|| implementation.certifiedHistoryTools?.some(name => !implementation.tools.includes(name) || fileCapability(name)?.risk === 'read')) throw new Error('Invalid provider implementation registration.')
			this.#implementations.set(implementation.provider, Object.freeze({
				...implementation,
				apiVersions: Object.freeze([...implementation.apiVersions]),
				tools: Object.freeze([...implementation.tools]),
				certifiedHistoryTools: Object.freeze([...(implementation.certifiedHistoryTools ?? [])]),
			}))
		}
	}

	available(discovered: readonly ProviderCapability[], published: readonly PublishedTool[]): readonly AvailableProviderTool[] {
		const result: AvailableProviderTool[] = []
		const seen = new Set<ProviderId>()
		for (const value of discovered) {
			if (seen.has(value.provider)) throw new Error('Duplicate native provider capability.')
			seen.add(value.provider)
			const implementation = this.#implementations.get(value.provider)
			if (!implementation || value.enabled !== true || value.canRead !== true || !value.policyVersion
				|| (value.discoveryOnly !== undefined && typeof value.discoveryOnly !== 'boolean')
				|| !implementation.apiVersions.includes(value.apiVersion)
				|| !['conditional', 'native', 'read-only'].includes(value.conflict)
				|| !['certified', 'audit-only', 'not-applicable'].includes(value.history)
				|| !['native-event', 'route', 'child-reload', 'manual'].includes(value.refresh)
				|| !Array.isArray(value.tools) || new Set(value.tools).size !== value.tools.length) continue
			for (const name of implementation.tools) {
				if (value.discoveryOnly && (value.provider !== 'calendar' || name !== 'nc_calendar_list')) continue
				const host = fileCapability(name)!
				const matches = published.filter(tool => tool.name === name)
				const contract = matches.length === 1 ? matches[0] : undefined
				if (!value.tools.includes(name) || !contract || !['read', 'write', 'destructive'].includes(contract.risk)) continue
				const risk = stricterRisk(host.risk, contract.risk)
				if (risk !== 'read' && (value.canWrite !== true || value.conflict === 'read-only')) continue
				// Native metadata cannot conjure an inverse that the host never implemented.
				const history = host.risk === 'read'
					? 'not-applicable'
					: value.history === 'certified' && value.conflict === 'conditional' && implementation.certifiedHistoryTools?.includes(name) ? 'certified' : 'audit-only'
				const capability = Object.freeze({ ...value, history, tools: Object.freeze([...value.tools]) })
				result.push(Object.freeze({
					name,
					provider: value.provider,
					risk,
					individualApproval: host.individualApproval || risk === 'destructive',
					capability,
				}))
			}
		}
		return Object.freeze(result)
	}

}
