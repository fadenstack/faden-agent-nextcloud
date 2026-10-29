/**
 * Nextcloud Whiteboard, Native Flow, Windmill, n8n, Agentfile, Notes, and Bookmarks adapters (NC14-NC16, NC20)
 *
 * Implements the unblocked no-upstream-change subsets with per-user engine connection broker,
 * strict declarative manifest validation, and exact individual approvals for mutations.
 */
import { defineTool, type ToolCallContext } from '@fadenstack/agents'
import type { FileApi } from './types.ts'
import { positiveInteger } from './adapterGuards.ts'

export const WHITEBOARD_MUTATIONS = new Set(['nc_whiteboard_create'])
export const FLOW_MUTATIONS = new Set(['nc_flow_create_draft', 'nc_flow_update_rule'])
export const WINDMILL_MUTATIONS = new Set(['nc_windmill_draft', 'nc_windmill_run'])
export const N8N_MUTATIONS = new Set(['nc_n8n_draft', 'nc_n8n_execute'])
export const NOTES_MUTATIONS = new Set(['nc_notes_create'])
export const BOOKMARKS_MUTATIONS = new Set(['nc_bookmarks_create'])

export interface WorkflowAgentfileHost {
	files: FileApi
	recordResult(callId: string, value: unknown, context: ToolCallContext): void
	commitWorkflowMutation?(name: string, args: Readonly<Record<string, unknown>>, call: ToolCallContext): Promise<unknown>
}

export function validateWhiteboardCreateArgs(args: Readonly<Record<string, unknown>>): { path: string; name: string } {
	const path = String(args.path ?? '').trim()
	const name = String(args.name ?? '').trim()
	if (!path) throw new Error('A valid destination directory path is required.')
	if (!name || !name.endsWith('.whiteboard') || name.length > 100) throw new Error('A valid name ending with .whiteboard is required.')
	return { path, name }
}

export function validateFlowCreateDraftArgs(args: Readonly<Record<string, unknown>>): {
	description: string
	operation: string
	config: Record<string, unknown>
} {
	const description = String(args.description ?? '').trim()
	const operation = String(args.operation ?? '').trim()
	if (!description || description.length > 200) throw new Error('Rule description must be between 1 and 200 characters.')
	if (!operation) throw new Error('Rule operation identifier is required.')
	const config = (args.config && typeof args.config === 'object' && !Array.isArray(args.config))
		? args.config as Record<string, unknown>
		: {}
	return { description, operation, config }
}

export function validateFlowUpdateRuleArgs(args: Readonly<Record<string, unknown>>): {
	ruleId: number
	active?: boolean
	deleteRule?: boolean
} {
	const ruleId = positiveInteger(args.ruleId, 'ruleId')
	const active = args.active !== undefined ? Boolean(args.active) : undefined
	const deleteRule = args.deleteRule !== undefined ? Boolean(args.deleteRule) : undefined
	return { ruleId, active, deleteRule }
}

export function validateWindmillDraftArgs(args: Readonly<Record<string, unknown>>): {
	path: string
	summary: string
	value: unknown
} {
	const path = String(args.path ?? '').trim()
	const summary = String(args.summary ?? '').trim()
	if (!path || !/^[ufr]\/[a-zA-Z0-9_\-/]+$/.test(path)) throw new Error('Valid Windmill resource path (e.g. u/user/flow) is required.')
	if (!summary || summary.length > 300) throw new Error('Summary must be between 1 and 300 characters.')
	if (args.value === undefined) throw new Error('Flow definition value is required.')
	return { path, summary, value: args.value }
}

export function validateWindmillRunArgs(args: Readonly<Record<string, unknown>>): {
	path: string
	args: Record<string, unknown>
} {
	const path = String(args.path ?? '').trim()
	if (!path) throw new Error('A valid Windmill runnable path is required.')
	const flowArgs = (args.args && typeof args.args === 'object' && !Array.isArray(args.args))
		? args.args as Record<string, unknown>
		: {}
	return { path, args: flowArgs }
}

export function validateN8nDraftArgs(args: Readonly<Record<string, unknown>>): {
	name: string
	nodes: unknown[]
	connections: unknown
} {
	const name = String(args.name ?? '').trim()
	if (!name || name.length > 200) throw new Error('Workflow name must be between 1 and 200 characters.')
	if (!Array.isArray(args.nodes)) throw new Error('Nodes array is required.')
	const connections = args.connections ?? {}
	return { name, nodes: args.nodes, connections }
}

export function validateN8nExecuteArgs(args: Readonly<Record<string, unknown>>): {
	workflowId: string
	data?: Record<string, unknown>
} {
	const workflowId = String(args.workflowId ?? '').trim()
	if (!workflowId) throw new Error('A valid n8n workflowId is required.')
	const data = (args.data && typeof args.data === 'object' && !Array.isArray(args.data))
		? args.data as Record<string, unknown>
		: undefined
	return { workflowId, data }
}

export function validateAgentfileReadArgs(args: Readonly<Record<string, unknown>>): { pathOrContent: string } {
	const pathOrContent = String(args.pathOrContent ?? '').trim()
	if (!pathOrContent) throw new Error('Manifest path or JSON string content is required.')
	return { pathOrContent }
}

export function validateNotesCreateArgs(args: Readonly<Record<string, unknown>>): {
	title: string
	content: string
	category?: string
} {
	const title = String(args.title ?? '').trim()
	const content = String(args.content ?? '')
	if (!title || title.length > 200) throw new Error('Note title must be between 1 and 200 characters.')
	const category = args.category !== undefined ? String(args.category).trim() : undefined
	return { title, content, category }
}

export function validateBookmarksCreateArgs(args: Readonly<Record<string, unknown>>): {
	url: string
	title?: string
	tags?: string[]
} {
	const url = String(args.url ?? '').trim()
	try {
		const parsed = new URL(url)
		if (!['http:', 'https:'].includes(parsed.protocol)) throw new Error('HTTP/HTTPS URL required.')
	} catch {
		throw new Error('A valid normalized HTTP/HTTPS URL is required.')
	}
	const title = args.title !== undefined ? String(args.title).trim() : undefined
	const tags = Array.isArray(args.tags) ? args.tags.map(t => String(t).trim()).filter(Boolean) : undefined
	return { url, title, tags }
}

export function whiteboardTools(host: WorkflowAgentfileHost) {
	return [
		defineTool({
			name: 'nc_whiteboard_read',
			toolClass: 'read',
			description: 'Read a stored .whiteboard file scene definition. Live canvas editing is held without upstream changes.',
			parameters: {
				type: 'object',
				properties: {
					path: { type: 'string', description: 'File path or resource ID of the .whiteboard file.' },
				},
				required: ['path'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const path = String(args.path ?? '').trim()
				if (!path) throw new Error('Path is required.')
				if (!host.files.whiteboard) throw new Error('Whiteboard service is unavailable.')
				const result = await host.files.whiteboard.readBoard(path, c.signal)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
		defineTool({
			name: 'nc_whiteboard_create',
			toolClass: 'destructive',
			description: 'Create a new stored .whiteboard file in an authorized directory after approval.',
			parameters: {
				type: 'object',
				properties: {
					path: { type: 'string', description: 'Target folder directory.' },
					name: { type: 'string', description: 'Board filename ending with .whiteboard.' },
				},
				required: ['path', 'name'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const { path, name } = validateWhiteboardCreateArgs(args)
				if (host.commitWorkflowMutation) {
					const result = await host.commitWorkflowMutation('nc_whiteboard_create', args, c)
					host.recordResult(c.callId, result, c)
					return result
				}
				if (!host.files.whiteboard) throw new Error('Whiteboard service is unavailable.')
				const result = await host.files.whiteboard.createBoard(path, name, c.signal)
				host.recordResult(c.callId, result, c)
				return { ...result, history: 'not-integrated' as const }
			},
		}),
	]
}

export function flowTools(host: WorkflowAgentfileHost) {
	return [
		defineTool({
			name: 'nc_flow_read',
			toolClass: 'read',
			description: 'Inspect personal Nextcloud Flow automation rules, conditions and triggers in user scope.',
			parameters: { type: 'object', properties: {}, additionalProperties: false },
			run: async (_args, c) => {
				c.signal.throwIfAborted()
				if (!host.files.flow) throw new Error('Flow service is unavailable.')
				const result = await host.files.flow.readRules(c.signal)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
		defineTool({
			name: 'nc_flow_create_draft',
			toolClass: 'destructive',
			description: 'Create a disabled personal draft Flow rule after approval. Activation requires a separate action.',
			parameters: {
				type: 'object',
				properties: {
					description: { type: 'string', minLength: 1, maxLength: 200 },
					operation: { type: 'string' },
					config: { type: 'object' },
				},
				required: ['description', 'operation'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const { description, operation, config } = validateFlowCreateDraftArgs(args)
				if (host.commitWorkflowMutation) {
					const result = await host.commitWorkflowMutation('nc_flow_create_draft', args, c)
					host.recordResult(c.callId, result, c)
					return result
				}
				if (!host.files.flow) throw new Error('Flow service is unavailable.')
				const result = await host.files.flow.createDraftRule(description, operation, config, c.signal)
				host.recordResult(c.callId, result, c)
				return { ...result, history: 'not-integrated' as const }
			},
		}),
		defineTool({
			name: 'nc_flow_update_rule',
			toolClass: 'destructive',
			description: 'Enable, disable or delete a personal Flow rule after individual approval.',
			parameters: {
				type: 'object',
				properties: {
					ruleId: { type: 'integer', minimum: 1 },
					active: { type: 'boolean' },
					deleteRule: { type: 'boolean' },
				},
				required: ['ruleId'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const { ruleId, active, deleteRule } = validateFlowUpdateRuleArgs(args)
				if (host.commitWorkflowMutation) {
					const result = await host.commitWorkflowMutation('nc_flow_update_rule', args, c)
					host.recordResult(c.callId, result, c)
					return result
				}
				if (!host.files.flow) throw new Error('Flow service is unavailable.')
				const result = await host.files.flow.updateRule(ruleId, active, deleteRule, c.signal)
				host.recordResult(c.callId, result, c)
				return { ...result, history: 'not-integrated' as const }
			},
		}),
	]
}

export function windmillTools(host: WorkflowAgentfileHost) {
	return [
		defineTool({
			name: 'nc_windmill_read',
			toolClass: 'read',
			description: 'Read authorized flows, scripts and job execution logs from Windmill via per-user credentials.',
			parameters: {
				type: 'object',
				properties: {
					path: { type: 'string', description: 'Optional workspace path prefix.' },
				},
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const path = args.path !== undefined ? String(args.path).trim() : undefined
				if (!host.files.windmill) throw new Error('Windmill service is unavailable.')
				const result = await host.files.windmill.readFlows(path, c.signal)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
		defineTool({
			name: 'nc_windmill_draft',
			toolClass: 'destructive',
			description: 'Create an inactive draft flow or script in Windmill with AI origin metadata after approval.',
			parameters: {
				type: 'object',
				properties: {
					path: { type: 'string', description: 'Resource path, e.g. u/user/flow' },
					summary: { type: 'string', minLength: 1, maxLength: 300 },
					value: { description: 'Flow definition value.' },
				},
				required: ['path', 'summary', 'value'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const { path, summary, value } = validateWindmillDraftArgs(args)
				if (host.commitWorkflowMutation) {
					const result = await host.commitWorkflowMutation('nc_windmill_draft', args, c)
					host.recordResult(c.callId, result, c)
					return result
				}
				if (!host.files.windmill) throw new Error('Windmill service is unavailable.')
				const result = await host.files.windmill.createDraftFlow(path, summary, value, c.signal)
				host.recordResult(c.callId, result, c)
				return { ...result, history: 'not-integrated' as const }
			},
		}),
		defineTool({
			name: 'nc_windmill_run',
			toolClass: 'destructive',
			description: 'Run an approved Windmill script or flow under the user connection after individual approval.',
			parameters: {
				type: 'object',
				properties: {
					path: { type: 'string', description: 'Flow or script path to execute.' },
					args: { type: 'object', description: 'Execution arguments.' },
				},
				required: ['path'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const { path, args: flowArgs } = validateWindmillRunArgs(args)
				if (host.commitWorkflowMutation) {
					const result = await host.commitWorkflowMutation('nc_windmill_run', args, c)
					host.recordResult(c.callId, result, c)
					return result
				}
				if (!host.files.windmill) throw new Error('Windmill service is unavailable.')
				const result = await host.files.windmill.runFlow(path, flowArgs, c.signal)
				host.recordResult(c.callId, result, c)
				return { ...result, history: 'no_undo_of_delivery_or_exposure_compensation_only' as const }
			},
		}),
	]
}

export function n8nTools(host: WorkflowAgentfileHost) {
	return [
		defineTool({
			name: 'nc_n8n_read',
			toolClass: 'read',
			description: 'Inspect n8n workflows and execution outcomes via per-user credentials.',
			parameters: { type: 'object', properties: {}, additionalProperties: false },
			run: async (_args, c) => {
				c.signal.throwIfAborted()
				if (!host.files.n8n) throw new Error('n8n service is unavailable.')
				const result = await host.files.n8n.readWorkflows(c.signal)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
		defineTool({
			name: 'nc_n8n_draft',
			toolClass: 'destructive',
			description: 'Create an inactive n8n workflow draft after approval.',
			parameters: {
				type: 'object',
				properties: {
					name: { type: 'string', minLength: 1, maxLength: 200 },
					nodes: { type: 'array' },
					connections: { type: 'object' },
				},
				required: ['name', 'nodes'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const { name, nodes, connections } = validateN8nDraftArgs(args)
				if (host.commitWorkflowMutation) {
					const result = await host.commitWorkflowMutation('nc_n8n_draft', args, c)
					host.recordResult(c.callId, result, c)
					return result
				}
				if (!host.files.n8n) throw new Error('n8n service is unavailable.')
				const result = await host.files.n8n.createDraftWorkflow(name, nodes, connections, c.signal)
				host.recordResult(c.callId, result, c)
				return { ...result, history: 'not-integrated' as const }
			},
		}),
		defineTool({
			name: 'nc_n8n_execute',
			toolClass: 'destructive',
			description: 'Execute an approved n8n workflow under the user connection after individual approval.',
			parameters: {
				type: 'object',
				properties: {
					workflowId: { type: 'string' },
					data: { type: 'object' },
				},
				required: ['workflowId'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const { workflowId, data } = validateN8nExecuteArgs(args)
				if (host.commitWorkflowMutation) {
					const result = await host.commitWorkflowMutation('nc_n8n_execute', args, c)
					host.recordResult(c.callId, result, c)
					return result
				}
				if (!host.files.n8n) throw new Error('n8n service is unavailable.')
				const result = await host.files.n8n.executeWorkflow(workflowId, data, c.signal)
				host.recordResult(c.callId, result, c)
				return { ...result, history: 'no_undo_of_delivery_or_exposure_compensation_only' as const }
			},
		}),
	]
}

export function agentfileTool(host: WorkflowAgentfileHost) {
	return [
		defineTool({
			name: 'nc_agentfile_read',
			toolClass: 'read',
			description: 'Read and validate a Microsoft Declarative Agent Manifest v1.9 (.agent file) without executing arbitrary code.',
			parameters: {
				type: 'object',
				properties: {
					pathOrContent: { type: 'string', description: 'Path to .agent file or raw manifest JSON string.' },
				},
				required: ['pathOrContent'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const { pathOrContent } = validateAgentfileReadArgs(args)
				if (!host.files.agentfile) throw new Error('Agentfile parser service is unavailable.')
				const result = await host.files.agentfile.readManifest(pathOrContent, c.signal)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
	]
}

export function notesTools(host: WorkflowAgentfileHost) {
	return [
		defineTool({
			name: 'nc_notes_read',
			toolClass: 'read',
			description: 'Read authorized Nextcloud Notes categories and note contents.',
			parameters: {
				type: 'object',
				properties: {
					category: { type: 'string', description: 'Optional category filter.' },
				},
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const category = args.category !== undefined ? String(args.category).trim() : undefined
				if (!host.files.notes) throw new Error('Notes service is unavailable.')
				const result = await host.files.notes.readNotes(category, c.signal)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
		defineTool({
			name: 'nc_notes_create',
			toolClass: 'destructive',
			description: 'Create or update an authorized note with title, content and category after approval.',
			parameters: {
				type: 'object',
				properties: {
					title: { type: 'string', minLength: 1, maxLength: 200 },
					content: { type: 'string' },
					category: { type: 'string' },
				},
				required: ['title', 'content'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const { title, content, category } = validateNotesCreateArgs(args)
				if (host.commitWorkflowMutation) {
					const result = await host.commitWorkflowMutation('nc_notes_create', args, c)
					host.recordResult(c.callId, result, c)
					return result
				}
				if (!host.files.notes) throw new Error('Notes service is unavailable.')
				const result = await host.files.notes.createNote(title, content, category, c.signal)
				host.recordResult(c.callId, result, c)
				return { ...result, history: 'not-integrated' as const }
			},
		}),
	]
}

export function bookmarksTools(host: WorkflowAgentfileHost) {
	return [
		defineTool({
			name: 'nc_bookmarks_read',
			toolClass: 'read',
			description: 'Search and read authorized Nextcloud Bookmarks and tags.',
			parameters: {
				type: 'object',
				properties: {
					query: { type: 'string', description: 'Optional search query.' },
				},
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const query = args.query !== undefined ? String(args.query).trim() : undefined
				if (!host.files.bookmarks) throw new Error('Bookmarks service is unavailable.')
				const result = await host.files.bookmarks.searchBookmarks(query, c.signal)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
		defineTool({
			name: 'nc_bookmarks_create',
			toolClass: 'destructive',
			description: 'Create a validated bookmark with URL, title and tags after individual approval.',
			parameters: {
				type: 'object',
				properties: {
					url: { type: 'string', description: 'Valid HTTP/HTTPS URL.' },
					title: { type: 'string' },
					tags: { type: 'array', items: { type: 'string' } },
				},
				required: ['url'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const { url, title, tags } = validateBookmarksCreateArgs(args)
				if (host.commitWorkflowMutation) {
					const result = await host.commitWorkflowMutation('nc_bookmarks_create', args, c)
					host.recordResult(c.callId, result, c)
					return result
				}
				if (!host.files.bookmarks) throw new Error('Bookmarks service is unavailable.')
				const result = await host.files.bookmarks.createBookmark(url, title, tags, c.signal)
				host.recordResult(c.callId, result, c)
				return { ...result, history: 'not-integrated' as const }
			},
		}),
	]
}
