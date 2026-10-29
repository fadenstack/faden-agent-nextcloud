/**
 * Nextcloud Collectives and Forms adapters and tools (NC13)
 *
 * Enforces hierarchy-aware knowledge creation, private draft form builder,
 * separate publication boundaries, and honest classification of collaborative limits.
 * Live collaborative body replacement is held without upstream changes.
 */
import { defineTool, type ToolCallContext } from '@fadenstack/agents'
import type { FileApi, FormQuestion } from './types.ts'
import { positiveInteger, optionalPositiveInteger } from './adapterGuards.ts'

export const COLLECTIVES_MUTATIONS = new Set([
	'nc_collectives_create_page',
	'nc_collectives_delete_page',
])

export const FORMS_MUTATIONS = new Set([
	'nc_forms_create_form',
	'nc_forms_submit_response',
])

export interface CollaborativeAppsHost {
	files: FileApi
	recordResult(callId: string, value: unknown, context: ToolCallContext): void
	commitCollectivesMutation?(name: string, args: Readonly<Record<string, unknown>>, call: ToolCallContext): Promise<unknown>
	commitFormsMutation?(name: string, args: Readonly<Record<string, unknown>>, call: ToolCallContext): Promise<unknown>
}

export function validateCollectivesCreatePageArgs(args: Readonly<Record<string, unknown>>): {
	collectiveId: number
	title: string
	parentId?: number
} {
	const collectiveId = positiveInteger(args.collectiveId, 'collectiveId')
	const title = String(args.title ?? '').trim()
	if (!title || title.length > 255) throw new Error('Page title must be between 1 and 255 characters.')
	const parentId = optionalPositiveInteger(args.parentId, 'parentId')
	return { collectiveId, title, parentId }
}

export function validateCollectivesDeletePageArgs(args: Readonly<Record<string, unknown>>): { pageId: number } {
	const pageId = positiveInteger(args.pageId, 'pageId')
	return { pageId }
}

export function validateFormsCreateFormArgs(args: Readonly<Record<string, unknown>>): {
	title: string
	questions: FormQuestion[]
} {
	const title = String(args.title ?? '').trim()
	if (!title || title.length > 255) throw new Error('Form title must be between 1 and 255 characters.')
	if (!Array.isArray(args.questions) || !args.questions.length || args.questions.length > 50) {
		throw new Error('Provide 1 to 50 questions.')
	}
	const questions: FormQuestion[] = (args.questions as Array<Record<string, unknown>>).map((q, idx) => {
		const text = String(q.text ?? '').trim()
		if (!text || text.length > 500) throw new Error(`Question ${idx + 1} text must be between 1 and 500 characters.`)
		const type = String(q.type ?? 'short').trim()
		const mandatory = q.mandatory === true
		const options = Array.isArray(q.options) ? q.options.map(o => String(o).trim()).filter(Boolean) : undefined
		return { id: idx + 1, text, type, mandatory, options }
	})
	return { title, questions }
}

export function validateFormsSubmitResponseArgs(args: Readonly<Record<string, unknown>>): {
	formId: number
	answers: Record<string, unknown>
} {
	const formId = positiveInteger(args.formId, 'formId')
	if (!args.answers || typeof args.answers !== 'object' || Array.isArray(args.answers)) {
		throw new Error('A structured answers object keyed by question ID is required.')
	}
	return { formId, answers: args.answers as Record<string, unknown> }
}

export function collectivesTools(host: CollaborativeAppsHost) {
	return [
		defineTool({
			name: 'nc_collectives_read',
			toolClass: 'read',
			description: 'Inspect authorized Collectives knowledge trees, collective names, page hierarchies and page metadata.',
			parameters: {
				type: 'object',
				properties: {
					collectiveId: { type: 'integer', minimum: 1, description: 'Optional collective ID to narrow scope.' },
				},
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const collectiveId = optionalPositiveInteger(args.collectiveId, 'collectiveId')
				if (!host.files.collectives) throw new Error('Collectives service is unavailable.')
				const result = await host.files.collectives.readHierarchy(collectiveId, c.signal)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
		defineTool({
			name: 'nc_collectives_create_page',
			toolClass: 'destructive',
			description: 'Create a new collective page under an authorized collective and optional parent hierarchy after approval.',
			parameters: {
				type: 'object',
				properties: {
					collectiveId: { type: 'integer', minimum: 1, description: 'Target collective ID.' },
					title: { type: 'string', minLength: 1, maxLength: 255, description: 'Page title.' },
					parentId: { type: 'integer', minimum: 1, description: 'Optional parent page ID.' },
				},
				required: ['collectiveId', 'title'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const { collectiveId, title, parentId } = validateCollectivesCreatePageArgs(args)
				if (host.commitCollectivesMutation) {
					const result = await host.commitCollectivesMutation('nc_collectives_create_page', args, c)
					host.recordResult(c.callId, result, c)
					return result
				}
				if (!host.files.collectives) throw new Error('Collectives service is unavailable.')
				const result = await host.files.collectives.createPage(collectiveId, title, parentId, c.signal)
				host.recordResult(c.callId, result, c)
				return { ...result, history: 'not-integrated' as const }
			},
		}),
		defineTool({
			name: 'nc_collectives_delete_page',
			toolClass: 'destructive',
			description: 'Trash or delete an authorized collective page after individual approval.',
			parameters: {
				type: 'object',
				properties: {
					pageId: { type: 'integer', minimum: 1, description: 'Target page ID.' },
				},
				required: ['pageId'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const { pageId } = validateCollectivesDeletePageArgs(args)
				if (host.commitCollectivesMutation) {
					const result = await host.commitCollectivesMutation('nc_collectives_delete_page', args, c)
					host.recordResult(c.callId, result, c)
					return result
				}
				if (!host.files.collectives) throw new Error('Collectives service is unavailable.')
				const result = await host.files.collectives.deletePage(pageId, c.signal)
				host.recordResult(c.callId, result, c)
				return { ...result, history: 'not-integrated' as const }
			},
		}),
	]
}

export function formsTools(host: CollaborativeAppsHost) {
	return [
		defineTool({
			name: 'nc_forms_read',
			toolClass: 'read',
			description: 'Read authorized Nextcloud Form definitions, question lists and aggregated response summaries.',
			parameters: {
				type: 'object',
				properties: {
					formId: { type: 'integer', minimum: 1, description: 'Optional form ID to inspect.' },
				},
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const formId = optionalPositiveInteger(args.formId, 'formId')
				if (!host.files.forms) throw new Error('Nextcloud Forms service is unavailable.')
				const result = await host.files.forms.readForm(formId, c.signal)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
		defineTool({
			name: 'nc_forms_create_form',
			toolClass: 'destructive',
			description: 'Create a private draft Nextcloud Form with structured questions after approval. Publication remains separate.',
			parameters: {
				type: 'object',
				properties: {
					title: { type: 'string', minLength: 1, maxLength: 255, description: 'Form title.' },
					questions: {
						type: 'array',
						items: {
							type: 'object',
							properties: {
								text: { type: 'string', minLength: 1, maxLength: 500 },
								type: { type: 'string', enum: ['short', 'long', 'multiple', 'checkbox'] },
								mandatory: { type: 'boolean' },
								options: { type: 'array', items: { type: 'string' } },
							},
							required: ['text', 'type'],
						},
						minItems: 1,
						maxItems: 50,
					},
				},
				required: ['title', 'questions'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const { title, questions } = validateFormsCreateFormArgs(args)
				if (host.commitFormsMutation) {
					const result = await host.commitFormsMutation('nc_forms_create_form', args, c)
					host.recordResult(c.callId, result, c)
					return result
				}
				if (!host.files.forms) throw new Error('Nextcloud Forms service is unavailable.')
				const result = await host.files.forms.createForm(title, questions, c.signal)
				host.recordResult(c.callId, result, c)
				return { ...result, history: 'not-integrated' as const }
			},
		}),
		defineTool({
			name: 'nc_forms_submit_response',
			toolClass: 'destructive',
			description: 'Submit answers to an authorized form after individual review. Submission is a consequential, irreversible action.',
			parameters: {
				type: 'object',
				properties: {
					formId: { type: 'integer', minimum: 1, description: 'Target form ID.' },
					answers: { type: 'object', description: 'Answers keyed by question ID.' },
				},
				required: ['formId', 'answers'],
				additionalProperties: false,
			},
			run: async (args, c) => {
				c.signal.throwIfAborted()
				const { formId, answers } = validateFormsSubmitResponseArgs(args)
				if (host.commitFormsMutation) {
					const result = await host.commitFormsMutation('nc_forms_submit_response', args, c)
					host.recordResult(c.callId, result, c)
					return result
				}
				if (!host.files.forms) throw new Error('Nextcloud Forms service is unavailable.')
				const result = await host.files.forms.submitResponse(formId, answers, c.signal)
				host.recordResult(c.callId, result, c)
				return { ...result, history: 'no_undo_of_delivery_or_exposure_compensation_only' as const }
			},
		}),
	]
}
