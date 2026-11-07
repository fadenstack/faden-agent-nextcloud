<script setup lang="ts">
import { computed, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { ToolCallPart, type ApprovalPart, type UiStrings } from '@fadenstack/ui'
import { Icon, useViewModel } from '@fadenstack/ui-vue'
import { approvalIcon } from '@fadenstack/ui-web'
import type { NextcloudAgent } from '../agent/createAgent.ts'
import { errorMessage } from '../integrations/nextcloud/files.ts'
import DavApprovalFields from './DavApprovalFields.vue'

// A file write, answered in place like the SDK's card, but showing the exact proposal (target, current and
// proposed content) and registering it with Nextcloud before it is allowed. Only once: no "always".
const props = defineProps<{ part: ApprovalPart; strings: UiStrings; host: NextcloudAgent }>()
const part = useViewModel(() => props.part)
const chat = useViewModel(() => props.host.chat)
function audience(value: unknown): string {
	if (value === 'private') return t('fadenstack_agent', 'Private')
	if (value === 'shared') return t('fadenstack_agent', 'Shared in Nextcloud')
	if (value === 'public') return t('fadenstack_agent', 'Public link access')
	return t('fadenstack_agent', 'Access could not be determined')
}
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
	case 'nc_files_copy': return t('fadenstack_agent', 'Copy file')
	case 'nc_files_move': return t('fadenstack_agent', 'Move file')
	case 'nc_files_share_readonly': return t('fadenstack_agent', 'Share file · read only')
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
	case 'nc_tables_add_column': return t('fadenstack_agent', 'Add table column')
	case 'nc_tables_add_row': return t('fadenstack_agent', 'Add table row')
	case 'nc_tables_update_row': return t('fadenstack_agent', 'Update table row')
	case 'nc_tables_delete_row': return t('fadenstack_agent', 'Delete table row')
	case 'nc_deck_create': return t('fadenstack_agent', 'Create Deck card')
	case 'nc_deck_patch': return t('fadenstack_agent', 'Update Deck card')
	case 'nc_deck_move': return t('fadenstack_agent', 'Move Deck card')
	case 'nc_deck_assign': return t('fadenstack_agent', 'Assign Deck card')
	case 'nc_deck_label': return t('fadenstack_agent', 'Label Deck card')
	case 'nc_mail_save_draft': return t('fadenstack_agent', 'Save Mail draft')
	case 'nc_mail_send_draft': return t('fadenstack_agent', 'Send Mail draft')
	case 'nc_talk_send_message': return t('fadenstack_agent', 'Send Talk message')
	default: return t('fadenstack_agent', 'Replace file')
	}
})
const approving = ref(false)
const organization = computed(() => ['nc_files_copy', 'nc_files_move', 'nc_files_share_readonly'].includes(part.value.toolName))
const structured = computed(() => /^(nc_tables_|nc_deck_|nc_mail_|nc_talk_)/.test(part.value.toolName))
const communicationSummary = computed(() => {
	switch (part.value.toolName) {
	case 'nc_mail_save_draft': return t('fadenstack_agent', 'Save only; no email is sent. Native last-write-wins: another editor can change this draft. Stop can leave a partial draft. Audit only; no Undo.')
	case 'nc_mail_send_draft': return t('fadenstack_agent', 'Send the reviewed draft. Native last-write-wins: another editor can change it. SMTP acceptance does not confirm delivery. Stop cannot retract an accepted email. Audit only; no Undo.')
	case 'nc_talk_send_message': return t('fadenstack_agent', 'Send to this room and its current audience. Native last-write-wins: room access can change. Normal Talk notifications may occur. Stop cannot retract an accepted message. Audit only; no Undo.')
	default: return ''
	}
})
const fields = computed(() => part.value.request.arguments.fields as { label: string; before: unknown; after: unknown }[] | undefined)
function fieldText(value: unknown): string { return value === null || value === undefined ? '—' : typeof value === 'string' ? value : JSON.stringify(value) }
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
		<details v-else-if="!organization && part.request.arguments.before !== null && part.request.arguments.before !== undefined">
			<summary>{{ t('fadenstack_agent', 'Current content') }}</summary>
			<pre class="fdn-approval-args" dir="auto">{{ typeof part.request.arguments.before === 'string' ? part.request.arguments.before : JSON.stringify(part.request.arguments.before, null, 2) }}</pre>
		</details>
		<template v-if="part.toolName === 'nc_files_create_folder'">
			<p class="fdn-approval-description">
				{{ part.request.arguments.executionReady === false ? t('fadenstack_agent', 'Folder creation is unavailable in this version. No folder will be created.') : t('fadenstack_agent', 'An empty folder will be created inside the target directory. Existing items are not overwritten.') }}
			</p>
		</template>
		<template v-else-if="structured">
			<p v-if="part.toolName === 'nc_tables_delete_row'">
				{{ t('fadenstack_agent', 'This row will be deleted. Its cell values are shown below.') }}
			</p>
			<dl v-if="fields?.length" class="fadenstack-file-fields">
				<div v-for="(field, index) in fields" :key="index" class="fadenstack-field-row">
					<dt>{{ field.label }}</dt>
					<dd><span v-if="field.before !== null && field.before !== undefined">{{ fieldText(field.before) }} → </span>{{ fieldText(field.after) }}</dd>
				</div>
			</dl>
			<dl class="fadenstack-file-fields">
				<dt>{{ t('fadenstack_agent', 'Native access') }}</dt><dd>{{ /^(nc_mail_|nc_talk_)/.test(part.toolName) ? audience(part.request.arguments.nativeAudience) : t('fadenstack_agent', 'Current table or board permissions; audience not fully determined') }}</dd>
				<dt>{{ t('fadenstack_agent', 'History') }}</dt><dd>{{ t('fadenstack_agent', 'Audit only · no Undo') }}</dd>
			</dl>
			<p>{{ communicationSummary || part.request.arguments.limitation }}</p>
			<details>
				<summary>{{ t('fadenstack_agent', 'Exact request details') }}</summary>
				<p v-if="communicationSummary">
					{{ part.request.arguments.limitation }}
				</p>
				<pre class="fdn-approval-args" dir="auto">{{ JSON.stringify(part.request.arguments.content, null, 2) }}</pre>
			</details>
		</template>
		<template v-else-if="organization">
			<dl class="fadenstack-file-fields">
				<dt>{{ t('fadenstack_agent', 'Source file') }}</dt>
				<dd>{{ (part.request.arguments.before as { source?: string })?.source }}</dd>
				<dt>{{ t('fadenstack_agent', 'Current access') }}</dt>
				<dd>{{ audience((part.request.arguments.before as { audience?: string })?.audience) }}</dd>
				<dt>{{ t('fadenstack_agent', 'Destination access') }}</dt>
				<dd>{{ audience((part.request.arguments.content as { audience?: string })?.audience) }}</dd>
				<template v-if="part.toolName === 'nc_files_share_readonly'">
					<dt>{{ t('fadenstack_agent', 'Recipient account') }}</dt>
					<dd>{{ (part.request.arguments.content as { recipientUserId?: string })?.recipientUserId }}</dd>
					<dt>{{ t('fadenstack_agent', 'New grant') }}</dt><dd>{{ t('fadenstack_agent', 'Read only · no email') }}</dd>
				</template>
				<template v-else>
					<dt>{{ t('fadenstack_agent', 'Existing items') }}</dt><dd>{{ t('fadenstack_agent', 'Never overwritten') }}</dd>
				</template>
				<dt>{{ t('fadenstack_agent', 'History') }}</dt><dd>{{ t('fadenstack_agent', 'Audit only · no Undo') }}</dd>
			</dl>
			<p>{{ part.request.arguments.limitation }}</p>
		</template>
		<template v-else-if="['nc_calendar_create', 'nc_calendar_patch', 'nc_calendar_delete'].includes(part.toolName)">
			<p class="fdn-approval-description">
				{{ part.toolName === 'nc_calendar_delete' ? t('fadenstack_agent', 'This private non-recurring calendar event will be deleted.') : part.toolName === 'nc_calendar_create' ? t('fadenstack_agent', 'A private non-recurring event will be created without attendees in this calendar.') : t('fadenstack_agent', 'The specified fields of this private calendar event will be updated.') }}
			</p>
			<div class="fdn-label">
				{{ part.toolName === 'nc_calendar_delete' ? t('fadenstack_agent', 'Target') : t('fadenstack_agent', 'Proposed event') }}
			</div>
			<DavApprovalFields :before="part.request.arguments.before"
				:after="part.request.arguments.content"
				:native-content="part.request.arguments.nativeContent"
				:deleted="part.toolName === 'nc_calendar_delete'" />
		</template>
		<template v-else-if="['nc_tasks_create', 'nc_tasks_patch', 'nc_tasks_delete'].includes(part.toolName)">
			<p class="fdn-approval-description">
				{{ part.toolName === 'nc_tasks_delete' ? t('fadenstack_agent', 'This task will be deleted.') : part.toolName === 'nc_tasks_create' ? t('fadenstack_agent', 'A private task will be created in this list.') : t('fadenstack_agent', 'The specified fields of this task will be updated.') }}
			</p>
			<div class="fdn-label">
				{{ part.toolName === 'nc_tasks_delete' ? t('fadenstack_agent', 'Target') : t('fadenstack_agent', 'Proposed task') }}
			</div>
			<DavApprovalFields :before="part.request.arguments.before"
				:after="part.request.arguments.content"
				:native-content="part.request.arguments.nativeContent"
				:deleted="part.toolName === 'nc_tasks_delete'" />
		</template>
		<template v-else-if="['nc_contacts_create', 'nc_contacts_patch', 'nc_contacts_delete'].includes(part.toolName)">
			<p class="fdn-approval-description">
				{{ part.toolName === 'nc_contacts_delete' ? t('fadenstack_agent', 'This contact will be deleted.') : part.toolName === 'nc_contacts_create' ? t('fadenstack_agent', 'A contact will be created in this address book.') : t('fadenstack_agent', 'The specified fields of this contact will be updated.') }}
			</p>
			<div class="fdn-label">
				{{ part.toolName === 'nc_contacts_delete' ? t('fadenstack_agent', 'Target') : t('fadenstack_agent', 'Proposed contact') }}
			</div>
			<DavApprovalFields :before="part.request.arguments.before"
				:after="part.request.arguments.content"
				:native-content="part.request.arguments.nativeContent"
				:deleted="part.toolName === 'nc_contacts_delete'" />
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

<style scoped>
.fadenstack-file-fields { display: grid; grid-template-columns: minmax(100px, 0.8fr) minmax(0, 1.5fr); gap: 8px 12px; margin: 12px 0; padding: 0; line-height: 1.5; }

.fadenstack-file-fields dt, .fadenstack-file-fields dd { float: none; width: auto; min-width: 0; margin: 0; padding: 0; text-align: start; overflow-wrap: anywhere; }

.fadenstack-file-fields dt { grid-column: 1; align-self: start; color: var(--color-text-maxcontrast); }

.fadenstack-file-fields dd { grid-column: 2; align-self: start; white-space: pre-wrap; }

.fadenstack-field-row { display: contents; }
</style>
