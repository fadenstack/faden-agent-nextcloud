<script setup lang="ts">
import { computed, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { ToolCallPart, type ApprovalPart, type UiStrings } from '@fadenstack/ui'
import { Icon, useViewModel } from '@fadenstack/ui-vue'
import { approvalIcon } from '@fadenstack/ui-web'
import type { NextcloudAgent } from '../agent/createAgent.ts'
import { errorMessage } from '../integrations/nextcloud/files.ts'

// A file write, answered in place like the SDK's card, but showing the exact proposal (target, current and
// proposed content) and registering it with Nextcloud before it is allowed. Only once: no "always".
const props = defineProps<{ part: ApprovalPart; strings: UiStrings; host: NextcloudAgent }>()
const part = useViewModel(() => props.part)
const chat = useViewModel(() => props.host.chat)
// RC2 represents an aborted pending approval as expired. Use the matching
// public tool outcome to distinguish Stop from an actual unanswered timeout.
const stateText = computed(() => {
	if (part.value.state !== 'expired') return part.value.stateText
	const stopped = chat.value.turns.some(turn => turn.parts.some(item => item instanceof ToolCallPart
		&& item.callId === part.value.request.callId && item.name === part.value.toolName && item.state === 'cancelled'))
	return stopped ? t('fadenstack_agent', 'Stopped') : part.value.stateText
})
const action = computed(() => {
	switch (part.value.toolName) {
	case 'nc_files_create_folder': return t('fadenstack_agent', 'Create folder')
	case 'nc_actions_revalidate': return t('fadenstack_agent', 'Verify file revision')
	case 'nc_actions_reconcile': return t('fadenstack_agent', 'Inspect outcome')
	case 'nc_actions_undo': return t('fadenstack_agent', 'Undo file change')
	case 'nc_actions_redo': return t('fadenstack_agent', 'Redo file change')
	case 'nc_create_text_file': return t('fadenstack_agent', 'Create file')
	case 'nc_calendar_create': return t('fadenstack_agent', 'Create event')
	case 'nc_calendar_patch': return t('fadenstack_agent', 'Update event')
	case 'nc_calendar_delete': return t('fadenstack_agent', 'Delete event')
	case 'nc_tasks_create': return t('fadenstack_agent', 'Create task')
	case 'nc_tasks_patch': return t('fadenstack_agent', 'Update task')
	case 'nc_tasks_delete': return t('fadenstack_agent', 'Delete task')
	case 'nc_contacts_create': return t('fadenstack_agent', 'Create contact')
	case 'nc_contacts_patch': return t('fadenstack_agent', 'Update contact')
	case 'nc_contacts_delete': return t('fadenstack_agent', 'Delete contact')
	default: return t('fadenstack_agent', 'Replace file')
	}
})
const approving = ref(false)
const problem = ref('')
async function approve() {
	approving.value = true
	problem.value = ''
	try {
		await props.host.approve(props.part)
	} catch (error) {
		problem.value = errorMessage(error)
		props.host.deny?.(props.part).catch(() => {})
		props.part.deny()
	} finally {
		approving.value = false
	}
}
function deny() {
	props.host.deny?.(props.part).catch(() => {})
	props.part.deny()
}
</script>

<template>
	<div class="fdn-approval fdn-approval-danger" role="group" :aria-label="action">
		<div class="fdn-approval-head">
			<Icon :name="approvalIcon(part)" />
			<strong>{{ action }}</strong>
			<span :class="`fdn-badge fdn-class-${part.request.toolClass}`">{{ part.classLabel }}</span>
		</div>
		<p v-if="part.reason" class="fdn-approval-reason" dir="auto">
			{{ part.reason }}
		</p>
		<p class="fdn-approval-target">
			{{ action }}:
			<strong>{{ part.request.arguments.target }}</strong>
		</p>
		<template v-if="part.toolName === 'nc_actions_revalidate'">
			<p>{{ (part.request.arguments.revalidation as { state?: string } | undefined)?.state === 'undone' ? t('fadenstack_agent', 'The content matches the recorded undone state.') : t('fadenstack_agent', 'The content matches the recorded saved state.') }}</p>
			<p>{{ t('fadenstack_agent', 'Verify this revision without changing the file. Undo or Redo will require another approval. Notifications and other effects are not verified.') }}</p>
			<details>
				<summary>{{ t('fadenstack_agent', 'Revision details') }}</summary>
				<p>{{ t('fadenstack_agent', 'Recorded revision') }}: {{ (part.request.arguments.revalidation as { recordedRevision?: string } | undefined)?.recordedRevision }}</p>
				<p>{{ t('fadenstack_agent', 'Current revision') }}: {{ (part.request.arguments.revalidation as { currentRevision?: string } | undefined)?.currentRevision }}</p>
			</details>
		</template>
		<template v-else-if="part.toolName === 'nc_actions_reconcile'">
			<p>{{ (part.request.arguments.resolution as { outcome?: string } | undefined)?.outcome === 'verified' ? t('fadenstack_agent', 'The file content matches the intended result.') : t('fadenstack_agent', 'The file content matches the state before this attempt.') }}</p>
			<p>{{ t('fadenstack_agent', 'Record this inspected result without changing the file. Notifications and other effects are not verified.') }}</p>
		</template>
		<details v-else-if="part.request.arguments.before !== null && part.request.arguments.before !== undefined">
			<summary>{{ t('fadenstack_agent', 'Current content') }}</summary>
			<pre class="fdn-approval-args" dir="auto">{{ typeof part.request.arguments.before === 'string' ? part.request.arguments.before : JSON.stringify(part.request.arguments.before, null, 2) }}</pre>
		</details>
		<template v-if="part.toolName === 'nc_files_create_folder'">
			<p class="fdn-approval-description">
				{{ part.request.arguments.executionReady === false ? t('fadenstack_agent', 'Folder creation is unavailable in this version. No folder will be created.') : t('fadenstack_agent', 'An empty folder will be created inside the target directory. Existing items are not overwritten.') }}
			</p>
		</template>
		<template v-else-if="['nc_calendar_create', 'nc_calendar_patch', 'nc_calendar_delete'].includes(part.toolName)">
			<p class="fdn-approval-description">
				{{ part.toolName === 'nc_calendar_delete' ? t('fadenstack_agent', 'This private non-recurring calendar event will be deleted.') : part.toolName === 'nc_calendar_create' ? t('fadenstack_agent', 'A private non-recurring event will be created without attendees in this calendar.') : t('fadenstack_agent', 'The specified fields of this private calendar event will be updated.') }}
			</p>
			<div class="fdn-label">
				{{ part.toolName === 'nc_calendar_delete' ? t('fadenstack_agent', 'Target') : t('fadenstack_agent', 'Proposed event') }}
			</div>
			<pre class="fdn-approval-args" dir="auto">{{ typeof part.request.arguments.content === 'string' ? part.request.arguments.content : JSON.stringify(part.request.arguments.content ?? part.request.arguments, null, 2) }}</pre>
		</template>
		<template v-else-if="['nc_tasks_create', 'nc_tasks_patch', 'nc_tasks_delete'].includes(part.toolName)">
			<p class="fdn-approval-description">
				{{ part.toolName === 'nc_tasks_delete' ? t('fadenstack_agent', 'This task will be deleted.') : part.toolName === 'nc_tasks_create' ? t('fadenstack_agent', 'A private task will be created in this list.') : t('fadenstack_agent', 'The specified fields of this task will be updated.') }}
			</p>
			<div class="fdn-label">
				{{ part.toolName === 'nc_tasks_delete' ? t('fadenstack_agent', 'Target') : t('fadenstack_agent', 'Proposed task') }}
			</div>
			<pre class="fdn-approval-args" dir="auto">{{ typeof part.request.arguments.content === 'string' ? part.request.arguments.content : JSON.stringify(part.request.arguments.content ?? part.request.arguments, null, 2) }}</pre>
		</template>
		<template v-else-if="['nc_contacts_create', 'nc_contacts_patch', 'nc_contacts_delete'].includes(part.toolName)">
			<p class="fdn-approval-description">
				{{ part.toolName === 'nc_contacts_delete' ? t('fadenstack_agent', 'This contact will be deleted.') : part.toolName === 'nc_contacts_create' ? t('fadenstack_agent', 'A contact will be created in this address book.') : t('fadenstack_agent', 'The specified fields of this contact will be updated.') }}
			</p>
			<div class="fdn-label">
				{{ part.toolName === 'nc_contacts_delete' ? t('fadenstack_agent', 'Target') : t('fadenstack_agent', 'Proposed contact') }}
			</div>
			<pre class="fdn-approval-args" dir="auto">{{ typeof part.request.arguments.content === 'string' ? part.request.arguments.content : JSON.stringify(part.request.arguments.content ?? part.request.arguments, null, 2) }}</pre>
		</template>
		<template v-else>
			<div class="fdn-label">
				{{ ['nc_actions_reconcile', 'nc_actions_revalidate'].includes(part.toolName) ? t('fadenstack_agent', 'Observed content') : t('fadenstack_agent', 'Proposed content') }}
			</div>
			<pre class="fdn-approval-args" dir="auto">{{ part.request.arguments.content }}</pre>
		</template>
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
				@click="deny">
				{{ strings.deny }}
			</button>
		</div>
		<div v-else :class="`fdn-approval-state fdn-approval-${part.state}`">
			{{ stateText }}
		</div>
		<p v-if="problem" role="alert">
			{{ problem }}
		</p>
	</div>
</template>
