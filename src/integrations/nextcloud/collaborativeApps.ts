/**
 * Nextcloud Collectives and Forms adapters and tools (NC13)
 *
 * Experimental definitions; native hierarchy, private form creation, publication
 * boundaries and result transports still require implementation and acceptance.
 * Live collaborative body replacement is held without upstream changes.
 */
import { defineTool } from '@fadenstack/agents'
import type { ToolCallContext } from '@fadenstack/agents'
import type { FileApi, FormQuestion } from './types.ts'
import { jsonRecord, optionalBoolean, optionalPositiveInteger, positiveInteger, stringArgument } from './adapterGuards.ts'

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
	const title = stringArgument(args.title, 'title').trim()
	if (!title || title.length > 255) {
		throw new Error('Page title must be between 1 and 255 characters.')
	}
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
	const title = stringArgument(args.title, 'title').trim()
	if (!title || title.length > 255) {
		throw new Error('Form title must be between 1 and 255 characters.')
	}
	if (!Array.isArray(args.questions) || !args.questions.length || args.questions.length > 50) {
		throw new Error('Provide 1 to 50 questions.')
	}
	const questions: FormQuestion[] = (args.questions as Array<Record<string, unknown>>).map((q, idx) => {
		if (!q || typeof q !== 'object' || Array.isArray(q)) {
			throw new Error('A structured question is required.')
		}
		const text = stringArgument(q.text, 'text').trim()
		if (!text || text.length > 500) {
			throw new Error(`Question ${idx + 1} text must be between 1 and 500 characters.`)
		}
		const type = (q.type === undefined ? 'short' : stringArgument(q.type, 'type')).trim()
		if (!['short', 'long', 'multiple', 'checkbox'].includes(type)) {
			throw new Error('Unsupported question type.')
		}
		const mandatory = optionalBoolean(q.mandatory, 'mandatory') ?? false
		if (q.options !== undefined && (!Array.isArray(q.options) || q.options.length > 50)) {
			throw new Error('Provide at most 50 choice options.')
		}
		const options = Array.isArray(q.options)
			? q.options.map((o) => {
					const option = stringArgument(o, 'option').trim()
					if (!option || option.length > 500) {
						throw new Error('Choice options must contain 1 to 500 characters.')
					}
					return option
				})
			: undefined
		if (type === 'multiple' || type === 'checkbox') {
			if (!options?.length || new Set(options).size !== options.length) {
				throw new Error('Choice questions require distinct options.')
			}
		} else if (options?.length) {
			throw new Error('Text questions cannot have choice options.')
		}
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
	return { formId, answers: jsonRecord(args.answers, 'answers') }
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
				if (!host.files.collectives) {
					throw new Error('Collectives service is unavailable.')
				}
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
				validateCollectivesCreatePageArgs(args)
				if (!host.commitCollectivesMutation) {
					throw new Error('The previewed mutation executor is unavailable.')
				}
				const result = await host.commitCollectivesMutation('nc_collectives_create_page', args, c)
				host.recordResult(c.callId, result, c)
				return result
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
				validateCollectivesDeletePageArgs(args)
				if (!host.commitCollectivesMutation) {
					throw new Error('The previewed mutation executor is unavailable.')
				}
				const result = await host.commitCollectivesMutation('nc_collectives_delete_page', args, c)
				host.recordResult(c.callId, result, c)
				return result
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
				if (!host.files.forms) {
					throw new Error('Nextcloud Forms service is unavailable.')
				}
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
				validateFormsCreateFormArgs(args)
				if (!host.commitFormsMutation) {
					throw new Error('The previewed mutation executor is unavailable.')
				}
				const result = await host.commitFormsMutation('nc_forms_create_form', args, c)
				host.recordResult(c.callId, result, c)
				return result
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
				validateFormsSubmitResponseArgs(args)
				if (!host.commitFormsMutation) {
					throw new Error('The previewed mutation executor is unavailable.')
				}
				const result = await host.commitFormsMutation('nc_forms_submit_response', args, c)
				host.recordResult(c.callId, result, c)
				return result
			},
		}),
	]
}
