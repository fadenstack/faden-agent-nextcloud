<script setup lang="ts">
import { onMounted, onBeforeUnmount, nextTick } from 'vue'
import { n, t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import { Icon } from '@fadenstack/ui-vue'
import type { NextcloudAgent } from '../agent/createAgent.ts'
import type { HistoryItem } from '../integrations/nextcloud/types.ts'
import { useObservable } from '../agent/useObservable.ts'

const props = defineProps<{ host: NextcloudAgent }>()
const emit = defineEmits<{ close: [] }>()
const version = useObservable(props.host.chat)
const loading = new AbortController()
onMounted(() => props.host.refreshHistory(loading.signal))
onBeforeUnmount(() => loading.abort())
async function choose(item: HistoryItem) {
	if (item.eligible === 'none' || props.host.chat.isBusy || !props.host.historyCommandsAvailable || paused(item)) return
	emit('close')
	// NcDialog is teleported outside the pane. Let it unmount before the native
	// dirty-editor guard inspects the page; no native dialog is ignored or bypassed.
	await nextTick()
	await props.host.invokeHistory(item.eligible, item.id, item.eligible === 'undo' ? t('fadenstack_agent', 'Undo file change') : item.eligible === 'redo' ? t('fadenstack_agent', 'Redo file change') : item.eligible === 'revalidate' ? t('fadenstack_agent', 'Verify file revision') : t('fadenstack_agent', 'Inspect outcome'))
}
async function inspect(item: HistoryItem) {
	try { await props.host.inspectProviderAction(item, loading.signal) } catch { /* The host exposes a safe inspection error. */ }
}
function observation(value: string): string {
	switch (value) {
	case 'matches_saved_content': return t('fadenstack_agent', 'Current content matches the recorded save.')
	case 'matches_saved_draft': return t('fadenstack_agent', 'The native draft matches the approved graph and origin record.')
	case 'job_running': return t('fadenstack_agent', 'Native job dispatch verified; execution is queued or running. External effects are not verified.')
	case 'job_succeeded': return t('fadenstack_agent', 'The native job reports success. External effects are not independently verified.')
	case 'job_failed': return t('fadenstack_agent', 'The native job reports failure. Partial external effects may remain; no retry is authorized.')
	case 'job_cancelled': return t('fadenstack_agent', 'The native job reports cancellation. Earlier effects are not undone.')
	case 'job_unconfirmed': return t('fadenstack_agent', 'The recorded job could not be confirmed. Do not repeat it.')
	case 'different_or_ambiguous': return t('fadenstack_agent', 'The saved workflow changed or the draft name is ambiguous.')
	case 'draft_absent': return t('fadenstack_agent', 'The draft was not found through the current engine connection.')
	case 'absent': return t('fadenstack_agent', 'The resource is absent and its parent is accessible.')
	case 'different_content': return t('fadenstack_agent', 'Current content differs from the recorded save.')
	case 'present': return t('fadenstack_agent', 'The resource is present.')
	case 'not_in_active_collection': return t('fadenstack_agent', 'The resource could not be verified in the active collection.')
	case 'recorded-attempt': return t('fadenstack_agent', 'Recorded steps only. Current native state has not been rechecked.')
	default: return t('fadenstack_agent', 'Current native state has not been verified.')
	}
}
function paused(item: HistoryItem): boolean {
	return !!props.host.history.availability?.writesPaused && ['undo', 'redo'].includes(item.eligible)
}
function state(item: HistoryItem): string {
	if (item.auditOnly && item.operation === 'nc_windmill_run' && item.state === 'applied') return t('fadenstack_agent', 'Dispatched · audit only')
	if (item.auditOnly) return item.state === 'applied' ? t('fadenstack_agent', 'Saved · audit only') : item.state === 'failed' ? t('fadenstack_agent', 'No change recorded · audit only') : t('fadenstack_agent', 'Inspect native outcome · audit only')
	if (item.eligible === 'revalidate') return t('fadenstack_agent', 'File revision changed')
	if (item.eligible === 'none' && ['applied', 'undone'].includes(item.state)) return t('fadenstack_agent', 'Restoration unavailable')
	if (item.state === 'applied') return t('fadenstack_agent', 'Saved')
	if (item.state === 'undone') return t('fadenstack_agent', 'Undone')
	if (item.state === 'redo_invalidated') return t('fadenstack_agent', 'Redo unavailable')
	if (item.state === 'expired') return t('fadenstack_agent', 'History expired')
	if (item.state === 'cancelled') return t('fadenstack_agent', 'Cancelled')
	if (item.state === 'failed') return t('fadenstack_agent', 'No content change recorded')
	return t('fadenstack_agent', 'Needs inspection')
}
function fileLink(item: HistoryItem): string | undefined {
	try {
		const url = new URL(item.fileUrl ?? '', location.href)
		if (url.origin === location.origin && /\/(?:index.php\/)?f\/[1-9][0-9]{0,14}$/.test(url.pathname) && !url.search && !url.hash) return url.href
	} catch { /* An invalid native link never becomes a navigation action. */ }
	return undefined
}
function operation(item: HistoryItem): string {
	if (item.operation === 'nc_files_copy') return t('fadenstack_agent', 'Copied file')
	if (item.operation === 'nc_files_move') return t('fadenstack_agent', 'Moved file')
	if (item.operation === 'nc_files_share_readonly') return t('fadenstack_agent', 'Shared file · read only')
	if (item.operation === 'nc_files_create_folder') return t('fadenstack_agent', 'Created folder')
	if (item.operation === 'nc_create_text_file') return t('fadenstack_agent', 'Created file')
	if (item.operation === 'nc_update_text_file') return t('fadenstack_agent', 'Updated file')
	return ''
}
</script>

<template>
	<NcDialog :open="true"
		size="large"
		:name="t('fadenstack_agent', 'Agent actions')"
		@update:open="!$event && emit('close')">
		<div class="fadenstack-action-history" :data-version="version">
			<p>{{ t('fadenstack_agent', 'Review recorded actions and available native checks. Restoration is available only for certified private text-file changes.') }}</p>
			<p>{{ t('fadenstack_agent', 'Nextcloud also keeps native file versions and deleted files. Open a file and choose Details → Versions for manual recovery. Native retention applies; versions do not represent every agent action.') }}</p>
			<p v-if="host.history.availability?.writesPaused" role="alert">
				{{ t('fadenstack_agent', 'Agent file changes are paused because an earlier action could not be safely checked. Contact your administrator. You can still inspect available outcomes.') }}
			</p>
			<p v-if="host.history.availability?.unavailableCount" role="status">
				{{ n('fadenstack_agent', '{count} earlier action could not be verified and cannot be restored. An earlier instance key or damaged history may be the cause.', '{count} earlier actions could not be verified and cannot be restored. An earlier instance key or damaged history may be the cause.', host.history.availability.unavailableCount, { count: host.history.availability.unavailableCount }) }}
			</p>
			<p v-if="host.history.availability?.earlierScopeCount" role="status">
				{{ n('fadenstack_agent', '{count} earlier action uses another connection or previous app settings. Its saved content expires by {date}.', '{count} earlier actions use another connection or previous app settings. Their saved content expires by {date}.', host.history.availability.earlierScopeCount, { count: host.history.availability.earlierScopeCount, date: host.history.availability.earlierContentExpiresBy ? new Date(host.history.availability.earlierContentExpiresBy * 1000).toLocaleString() : '' }) }}
			</p>
			<p v-if="host.history.items.some(item => !item.auditOnly) && !host.historyCommandsAvailable" role="status">
				{{ t('fadenstack_agent', 'Action controls are unavailable on this version. Contact your administrator.') }}
			</p>
			<p v-if="host.history.loading" role="status">
				{{ t('fadenstack_agent', 'Loading actions…') }}
			</p>
			<p v-else-if="host.history.error" role="alert">
				{{ host.history.error }}
			</p>
			<p v-else-if="!host.history.items.length">
				{{ t('fadenstack_agent', 'No recorded actions are available with current native access.') }}
			</p>
			<section v-if="host.history.inspection" role="status" class="fadenstack-action-inspection">
				<strong>{{ host.history.inspection.outcome === 'verified_committed' ? t('fadenstack_agent', 'Recorded commitment verified') : host.history.inspection.outcome === 'not_dispatched' ? t('fadenstack_agent', 'No engine action was dispatched') : t('fadenstack_agent', 'Completion remains uncertain') }}</strong>
				<p>{{ host.history.inspection.message }}</p>
				<p>{{ observation(host.history.inspection.observed) }}</p>
				<small>{{ t('fadenstack_agent', 'Action reference') }}: {{ host.history.inspection.action.id }}</small>
			</section>
			<ul v-if="!host.history.loading && host.history.items.length">
				<li v-for="item in host.history.items" :key="item.id">
					<div>
						<strong>{{ item.name }}</strong>
						<span v-if="operation(item)">{{ operation(item) }}</span>
						<span v-if="item.target">{{ item.target }}</span>
						<span v-if="item.auditOnly">{{ t('fadenstack_agent', 'Action audit') }} · {{ item.provider }}</span>
						<span>{{ state(item) }} · {{ new Date(item.createdAt * 1000).toLocaleString() }}</span>
						<a v-if="fileLink(item)"
							:href="fileLink(item)"
							target="_blank"
							rel="noopener noreferrer">{{ t('fadenstack_agent', 'Open native file') }}</a>
					</div>
					<NcButton v-if="item.auditOnly" :disabled="host.chat.isBusy" @click="inspect(item)">
						{{ t('fadenstack_agent', 'Inspect outcome') }}
					</NcButton>
					<NcButton v-if="item.eligible !== 'none'" :disabled="host.chat.isBusy || !host.historyCommandsAvailable || paused(item)" @click="choose(item)">
						<template #icon>
							<Icon name="history" :size="20" />
						</template>
						{{ item.eligible === 'undo' ? t('fadenstack_agent', 'Undo') : item.eligible === 'redo' ? t('fadenstack_agent', 'Redo') : item.eligible === 'revalidate' ? t('fadenstack_agent', 'Verify file revision') : t('fadenstack_agent', 'Inspect outcome') }}
					</NcButton>
				</li>
			</ul>
		</div>
		<template #actions>
			<NcButton @click="emit('close')">
				{{ t('fadenstack_agent', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.fadenstack-action-history { display: grid; gap: 12px; }

.fadenstack-action-history ul { list-style: none; padding: 0; margin: 0; }

.fadenstack-action-history li { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--color-border); }

.fadenstack-action-history li > div { display: grid; gap: 4px; flex: 1; min-width: 0; overflow-wrap: anywhere; }

.fadenstack-action-history li span { color: var(--color-text-maxcontrast); }
</style>
