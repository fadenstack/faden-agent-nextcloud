<script setup lang="ts">
import { ref } from 'vue'
import { t } from '@nextcloud/l10n'
import type { ApprovalPart, UiStrings } from '@fadenstack/ui'
import { Icon, useViewModel } from '@fadenstack/ui-vue'
import { approvalIcon } from '@fadenstack/ui-web'
import type { NextcloudAgent } from '../agent/createAgent.ts'
import { errorMessage } from '../integrations/nextcloud/files.ts'

// A file write, answered in place like the SDK's card, but showing the exact proposal (target, current and
// proposed content) and registering it with Nextcloud before it is allowed. Only once: no "always".
const props = defineProps<{ part: ApprovalPart; strings: UiStrings; host: NextcloudAgent }>()
const part = useViewModel(() => props.part)
const approving = ref(false)
const problem = ref('')
async function approve() {
	approving.value = true
	problem.value = ''
	try { await props.host.approve(props.part) } catch (error) { problem.value = errorMessage(error); props.part.deny() } finally { approving.value = false }
}
</script>

<template>
	<div class="fdn-approval fdn-approval-danger" role="group" :aria-label="part.title">
		<div class="fdn-approval-head">
			<Icon :name="approvalIcon(part)" />
			<strong>{{ part.title }}</strong>
			<span :class="`fdn-badge fdn-class-${part.request.toolClass}`">{{ part.classLabel }}</span>
		</div>
		<p v-if="part.reason" class="fdn-approval-reason" dir="auto">
			{{ part.reason }}
		</p>
		<p class="fdn-approval-target">
			{{ part.toolName === 'nc_actions_undo' ? t('fadenstack_agent', 'Undo file change') : part.toolName === 'nc_actions_redo' ? t('fadenstack_agent', 'Redo file change') : part.toolName === 'nc_create_text_file' ? t('fadenstack_agent', 'Create file') : t('fadenstack_agent', 'Replace file') }}:
			<strong>{{ part.request.arguments.target }}</strong>
		</p>
		<details v-if="part.request.arguments.before !== null && part.request.arguments.before !== undefined">
			<summary>{{ t('fadenstack_agent', 'Current content') }}</summary>
			<pre class="fdn-approval-args" dir="auto">{{ part.request.arguments.before }}</pre>
		</details>
		<div class="fdn-label">
			{{ t('fadenstack_agent', 'Proposed content') }}
		</div>
		<pre class="fdn-approval-args" dir="auto">{{ part.request.arguments.content }}</pre>
		<div v-if="part.isPending" class="fdn-approval-actions">
			<button type="button"
				class="fdn-button fdn-primary"
				:disabled="approving"
				@click="approve">
				{{ strings.allow }}
			</button>
			<button type="button"
				class="fdn-button fdn-quiet"
				:disabled="approving"
				@click="part.deny()">
				{{ strings.deny }}
			</button>
		</div>
		<div v-else :class="`fdn-approval-state fdn-approval-${part.state}`">
			{{ part.stateText }}
		</div>
		<p v-if="problem" role="alert">
			{{ problem }}
		</p>
	</div>
</template>
