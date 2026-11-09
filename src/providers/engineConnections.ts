/** Human settings metadata only. No credential getter or agent-tool registration. */
export interface EngineWorkspaceLink { readonly connectionId: string; readonly href: string }
export function engineWorkspaceLinks(value: unknown): readonly EngineWorkspaceLink[] {
	if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).some(k => k !== 'workspaces')
		|| !Array.isArray((value as { workspaces?: unknown }).workspaces)) throw new Error('Invalid native workflow workspaces.')
	const rows = (value as { workspaces: unknown[] }).workspaces
	if (rows.length > 8) throw new Error('Too many native workflow workspaces.')
	const ids = new Set<string>()
	return rows.map(row => {
		if (!row || typeof row !== 'object' || Array.isArray(row)) throw new Error('Invalid native workflow workspace.')
		const v = row as Record<string, unknown>
		if (Object.keys(v).length !== 2 || typeof v.connectionId !== 'string' || !/^[a-z][a-z0-9_-]{0,31}$/.test(v.connectionId)
			|| ids.has(v.connectionId) || typeof v.href !== 'string') throw new Error('Invalid native workflow workspace.')
		const url = new URL(v.href, location.href)
		if (url.origin !== location.origin || url.username || url.password || url.search || url.hash
			|| !url.pathname.endsWith('/apps/fadenstack_agent/engines/workspace/' + v.connectionId)) throw new Error('Invalid native workflow workspace link.')
		ids.add(v.connectionId)
		return Object.freeze({ connectionId: v.connectionId, href: url.href })
	})
}

export interface EngineConnectionSummary {
	readonly id: string
	readonly provider: 'windmill' | 'n8n' | 'unavailable'
	readonly label: string
	readonly baseUrl: string
	readonly workspace: string
	readonly version: string
	readonly revision: string
	readonly connected: boolean
	readonly expiresAt: number | null
	readonly setupAllowed: boolean
	readonly keyAccess: string
}

export interface EngineWebhookSummary { readonly id: string; readonly connectionId: string; readonly workflowId: string; readonly path: string; readonly secretStored: boolean }
export function engineWebhookList(value: unknown): readonly EngineWebhookSummary[] {
	if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).some(key => key !== 'targets')
		|| !Array.isArray((value as { targets?: unknown }).targets)) throw new Error('Invalid published webhook targets.')
	const rows = (value as { targets: unknown[] }).targets
	if (rows.length > 16) throw new Error('Narrow the published webhook targets.')
	const ids = new Set<string>()
	return rows.map(row => {
		if (!row || typeof row !== 'object' || Array.isArray(row)) throw new Error('Invalid published webhook target.')
		const r = row as Record<string, unknown>
		if (Object.keys(r).length !== 5 || Object.keys(r).some(key => !['id', 'connectionId', 'workflowId', 'path', 'secretStored'].includes(key))
			|| typeof r.id !== 'string' || !/^[a-z][a-z0-9_-]{0,31}$/.test(r.id) || ids.has(r.id)
			|| typeof r.connectionId !== 'string' || !/^[a-z][a-z0-9_-]{0,31}$/.test(r.connectionId)
			|| typeof r.workflowId !== 'string' || !/^[A-Za-z0-9_-]{1,64}$/.test(r.workflowId)
			|| typeof r.path !== 'string' || !/^[A-Za-z0-9_-]{1,120}$/.test(r.path) || typeof r.secretStored !== 'boolean') throw new Error('Invalid published webhook target.')
		ids.add(r.id)
		return Object.freeze({ ...r }) as unknown as EngineWebhookSummary
	})
}

export function engineConnectionSummary(value: unknown): EngineConnectionSummary {
	if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('Invalid workflow connection metadata.')
	const item = value as Record<string, unknown>
	const keys = ['id', 'provider', 'label', 'baseUrl', 'workspace', 'version', 'revision', 'connected', 'expiresAt', 'setupAllowed', 'keyAccess']
	if (Object.keys(item).some(key => !keys.includes(key)) || keys.some(key => !(key in item))
		|| typeof item.id !== 'string' || !/^[a-z][a-z0-9_-]{0,31}$/.test(item.id)
		|| !['windmill', 'n8n', 'unavailable'].includes(item.provider as string)
		|| typeof item.label !== 'string' || !item.label || item.label.length > 160
		|| typeof item.baseUrl !== 'string' || item.baseUrl.length > 2048
		|| typeof item.workspace !== 'string' || item.workspace.length > 64
		|| typeof item.version !== 'string' || item.version.length > 30
		|| typeof item.revision !== 'string' || !/^[a-f0-9]{64}$/.test(item.revision)
		|| typeof item.connected !== 'boolean' || typeof item.setupAllowed !== 'boolean'
		|| (item.expiresAt !== null && (!Number.isSafeInteger(item.expiresAt) || (item.expiresAt as number) < 1))
		|| typeof item.keyAccess !== 'string' || item.keyAccess.length > 1000
		|| (item.provider === 'unavailable' && (item.setupAllowed || item.connected))) throw new Error('Invalid workflow connection metadata.')
	return Object.freeze({ ...item }) as unknown as EngineConnectionSummary
}

export function engineConnectionList(value: unknown): readonly EngineConnectionSummary[] {
	if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).some(key => key !== 'connections')
		|| !Array.isArray((value as { connections?: unknown }).connections)) throw new Error('Invalid workflow connections.')
	const values = (value as { connections: unknown[] }).connections
	if (values.length > 64) throw new Error('Narrow the workflow connection list.')
	const result = values.map(engineConnectionSummary)
	if (new Set(result.map(item => item.id)).size !== result.length) throw new Error('Duplicate workflow connection metadata.')
	return Object.freeze(result)
}

export function changedEngineConnection(value: unknown, id: string, revision: string, connected: boolean): EngineConnectionSummary {
	const result = engineConnectionSummary(value)
	if (result.id !== id || result.revision === revision || result.connected !== connected) throw new Error('The workflow connection change could not be confirmed.')
	return result
}
