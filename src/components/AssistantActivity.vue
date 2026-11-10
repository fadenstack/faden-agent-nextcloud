<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import { AgentAvatar, Icon } from '@fadenstack/ui-vue'
import type { Runtime } from '../agent/runtime.ts'

const props = defineProps<{ runtime?: Runtime; open: boolean }>()
const emit = defineEmits<{ show: []; minimise: [] }>()
const version = ref(0)
watch(() => props.runtime?.state.host, (host, _previous, cleanup) => {
	version.value++
	if (host) cleanup(host.chat.subscribe(() => { version.value++ }))
}, { immediate: true })
const busy = computed(() => { void version.value; return !!props.runtime?.state.host?.chat.isBusy })
const phase = computed(() => props.runtime?.workspace.state.phase)
const label = computed(() => phase.value === 'dispatching' ? props.runtime?.state.host?.history.verifying ? t('fadenstack_agent', 'Verifying file revision…') : props.runtime?.state.host?.history.inspecting ? t('fadenstack_agent', 'Recording outcome…') : t('fadenstack_agent', 'Saving changes…') : phase.value === 'refreshing' ? t('fadenstack_agent', 'Updating the view…') : t('fadenstack_agent', 'Assistant is working…'))
const latest = computed(() => props.runtime?.workspace.state.latest)
function dismiss() { props.runtime?.workspace.dismissReceipt() }
</script>

<template>
	<aside v-if="busy || latest"
		class="fadenstack-activity"
		:class="{ 'fadenstack-activity--chat-open': open }"
		:aria-label="t('fadenstack_agent', 'Assistant activity')">
		<div v-if="busy" class="fadenstack-activity__working">
			<AgentAvatar :size="24" />
			<span role="status">{{ label }}</span>
			<button v-if="open" type="button" @click="emit('minimise')">
				{{ t('fadenstack_agent', 'Hide chat') }}
			</button>
			<button v-else type="button" @click="emit('show')">
				{{ t('fadenstack_agent', 'Show chat') }}
			</button>
			<button type="button" class="fadenstack-activity__stop" @click="runtime?.state.host?.stop()">
				<Icon name="stop" :size="16" />
				{{ t('fadenstack_agent', 'Stop') }}
			</button>
		</div>
		<div v-else-if="latest" role="status">
			<strong v-if="latest.outcome === 'committed'">{{ ['nc_windmill_run', 'nc_n8n_execute'].includes(latest.operation) ? t('fadenstack_agent', 'Execution dispatched: {name}', { name: latest.target }) : latest.operation === 'nc_mail_send_draft' ? t('fadenstack_agent', 'SMTP accepted: {name}', { name: latest.target }) : latest.operation === 'nc_talk_send_message' ? t('fadenstack_agent', 'Message sent: {name}', { name: latest.target }) : latest.operation === 'create_folder' ? t('fadenstack_agent', 'Folder created: {name}', { name: latest.target }) : latest.operation === 'revalidate' ? t('fadenstack_agent', 'File revision verified: {name}', { name: latest.target }) : latest.operation === 'reconcile' ? t('fadenstack_agent', 'Outcome recorded: {name}', { name: latest.target }) : t('fadenstack_agent', 'Saved: {name}', { name: latest.target }) }}</strong>
			<strong v-else>{{ latest.message }}</strong>
			<p v-if="latest.outcome === 'committed' && latest.operation === 'nc_mail_send_draft'">
				{{ t('fadenstack_agent', 'Recipient delivery is not confirmed. Sending has no Undo.') }}
			</p>
			<p v-if="latest.outcome === 'committed' && ['nc_windmill_run', 'nc_n8n_execute'].includes(latest.operation)">
				{{ t('fadenstack_agent', 'Inspect Agent actions for native job status. External effects are not verified and cannot be undone.') }}
			</p>
			<p v-if="latest.outcome === 'committed' && !['reconcile', 'revalidate', 'nc_windmill_run', 'nc_n8n_execute'].includes(latest.operation) && latest.view !== 'refreshed'">
				{{ ['nc_windmill_draft', 'nc_n8n_draft'].includes(latest.operation) ? t('fadenstack_agent', 'Saved in the workflow engine. Check the native editor display; a child load does not verify the shown result. Save or cancel native edits before reloading.') : latest.view === 'not_visible' ? t('fadenstack_agent', 'Saved in Nextcloud. Open the changed resource to see it.') : t('fadenstack_agent', 'Saved in Nextcloud. Refresh the native view after saving or cancelling your edits.') }}
			</p>
			<button v-if="!open" type="button" @click="emit('show')">
				{{ t('fadenstack_agent', 'Show chat') }}
			</button>
			<button type="button" :aria-label="t('fadenstack_agent', 'Dismiss activity')" @click="dismiss">
				<Icon name="close" :size="16" />
			</button>
		</div>
	</aside>
</template>

<style scoped>
.fadenstack-activity { --fdn-agent-plate: var(--color-main-text); --fdn-agent-plate-side: var(--color-text-maxcontrast); --fdn-agent-eye: var(--color-main-background); --fdn-agent-pupil: var(--color-main-text); position: fixed; z-index: 1900; inset-block-start: calc(var(--header-height, 50px) + 12px); inset-inline-end: 16px; max-width: min(390px, calc(100vw - 32px)); padding: 8px 12px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 12px); background: var(--color-main-background); color: var(--color-main-text); box-shadow: 0 2px 12px #0003; font: var(--default-font-size, 15px) / 1.5 var(--font-face, system-ui); overflow-wrap: anywhere; }

.fadenstack-activity__working { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }

.fadenstack-activity button { display: inline-flex; align-items: center; gap: 4px; min-height: 34px; padding: 4px 8px; border: 1px solid var(--color-border); border-radius: var(--border-radius-element, 8px); background: var(--color-background-hover); color: inherit; font: inherit; cursor: pointer; }

.fadenstack-activity button:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }

.fadenstack-activity__stop { font-weight: 600; }

.fadenstack-activity p { margin: 4px 0; }

@media (width >= 1024px) {
	.fadenstack-activity--chat-open { inset-inline-end: 444px; }

	body.fadenstack-engine-workspace-page .fadenstack-activity { inset-block-start: auto; inset-block-end: 24px; }
}

@media (width < 1024px) {
	.fadenstack-activity--chat-open { inset-block-start: calc(var(--header-height, 50px) + 90px); }
}
</style>
