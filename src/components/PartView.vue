<script setup lang="ts">
import { computed, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { MarkdownPart, ToolCallPart, ApprovalPart, NoticePart, ReasoningPart, CitationsPart, CardPart, TablePart, ProgressPart, DiffPart, markdownToHtml, type ResponsePart } from '@fadenstack/ui'
import DOMPurify from 'dompurify'
import type { NextcloudAgent } from '../agent/createAgent.ts'
import { useObservable } from '../agent/useObservable.ts'
import { errorMessage } from '../integrations/nextcloud/files.ts'

const props = defineProps<{ part: ResponsePart; host: NextcloudAgent }>()
const version = useObservable(props.part)
const html = computed(() => {
	void version.value
	if (!(props.part instanceof MarkdownPart)) return ''
	const safe = DOMPurify.sanitize(markdownToHtml(props.part.text), {
		USE_PROFILES: { html: true }, FORBID_TAGS: ['img', 'form', 'input', 'style'], FORBID_ATTR: ['style'], RETURN_DOM: true,
	})
	if (!(safe instanceof HTMLElement)) return ''
	// Keep the conversation open when following source or result links.
	for (const link of safe.querySelectorAll('a[href]')) {
		link.setAttribute('target', '_blank')
		link.setAttribute('rel', 'noopener noreferrer')
	}
	return safe.innerHTML
})
const problem = ref('')
const approving = ref(false)
async function approve(part: ApprovalPart) {
	approving.value = true
	try { await props.host.approve(part) } catch (error) { problem.value = errorMessage(error); part.deny() } finally { approving.value = false }
}
</script>
<template>
	<div :data-version="version">
		<!-- HTML comes from the SDK Markdown serializer and is sanitized again at this boundary. -->
		<div v-if="part instanceof MarkdownPart" class="fdn-md" v-html="html" />
		<div v-else-if="part instanceof ApprovalPart" class="fdn-approval" :class="{ 'fdn-approval-danger': part.request.arguments.proposalId }">
			<div class="fdn-approval-head">
				<strong>{{ part.title }}</strong>
			</div>
			<p class="fdn-approval-reason">
				{{ part.reason }}
			</p>
			<template v-if="part.request.arguments.proposalId">
				<p>{{ part.toolName === 'nc_create_text_file' ? t('fadenstack_agent', 'Create file') : t('fadenstack_agent', 'Replace file') }}: <strong>{{ part.request.arguments.target }}</strong></p>
				<details v-if="part.request.arguments.before !== null">
					<summary>{{ t('fadenstack_agent', 'Current content') }}</summary><pre>{{ part.request.arguments.before }}</pre>
				</details>
				<p>{{ t('fadenstack_agent', 'Proposed content') }}</p>
				<pre>{{ part.request.arguments.content }}</pre>
			</template>
			<pre v-else>{{ part.argumentsText }}</pre>
			<div v-if="part.isPending" class="fdn-actions">
				<button type="button"
					class="fdn-primary"
					:disabled="approving"
					@click="approve(part)">
					{{ t('fadenstack_agent', 'Allow once') }}
				</button>
				<button type="button" :disabled="approving" @click="part.deny()">
					{{ t('fadenstack_agent', 'Deny') }}
				</button>
			</div>
			<p v-else>
				{{ part.stateText }}
			</p>
			<p v-if="problem" role="alert">
				{{ problem }}
			</p>
		</div>
		<template v-else-if="part instanceof ToolCallPart">
			<button type="button" class="fdn-tool-line" @click="part.toggle()">
				{{ part.title }}
			</button>
			<template v-if="part.isExpanded">
				<pre>{{ part.argumentsText }}</pre><pre>{{ part.resultText }}</pre>
			</template>
		</template>
		<template v-else-if="part instanceof NoticePart">
			<strong>{{ part.title }}</strong><p>{{ part.message }}</p>
		</template>
		<template v-else-if="part instanceof ReasoningPart">
			<button type="button" class="fdn-tool-line" @click="part.toggle()">
				{{ part.title }}
			</button><pre v-if="part.isExpanded">{{ part.text }}</pre>
		</template>
		<template v-else-if="part instanceof CitationsPart">
			<strong>{{ part.title }}</strong><p v-for="(citation, i) in part.citations" :key="i">
				{{ citation.title }} {{ citation.detail }}
			</p>
		</template>
		<template v-else-if="part instanceof CardPart">
			<strong>{{ part.title }}</strong><p v-for="(field, i) in part.fields" :key="i">
				{{ field.label }}: {{ field.value }}
			</p>
		</template>
		<template v-else-if="part instanceof TablePart">
			<table>
				<thead>
					<tr>
						<th v-for="(column, i) in part.headers" :key="i">
							{{ column }}
						</th>
					</tr>
				</thead><tbody>
					<tr v-for="(row, i) in part.rows" :key="i">
						<td v-for="(cell, j) in row" :key="j">
							{{ cell }}
						</td>
					</tr>
				</tbody>
			</table>
		</template>
		<template v-else-if="part instanceof ProgressPart">
			<p role="status">
				{{ part.label }}
			</p>
		</template>
		<template v-else-if="part instanceof DiffPart">
			<strong>{{ part.title }}</strong><pre v-for="(line, i) in part.lines" :key="i">{{ line.kind }} {{ line.text }}</pre>
		</template>
	</div>
</template>
