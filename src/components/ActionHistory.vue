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
function paused(item: HistoryItem): boolean {
	return !!props.host.history.availability?.writesPaused && ['undo', 'redo'].includes(item.eligible)
}
function state(item: HistoryItem): string {
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
</script>

<template>
	<NcDialog :open="true" :name="t('fadenstack_agent', 'Agent actions')" @update:open="!$event && emit('close')">
		<div class="fadenstack-action-history" :data-version="version">
			<p>{{ t('fadenstack_agent', 'Review a private text-file change before restoring it. Protection settings and later edits can prevent restoration.') }}</p>
			<p v-if="host.history.availability?.writesPaused" role="alert">
				{{ t('fadenstack_agent', 'Agent file changes are paused because an earlier action could not be safely checked. Contact your administrator. You can still inspect available outcomes.') }}
			</p>
			<p v-if="host.history.availability?.unavailableCount" role="status">
				{{ n('fadenstack_agent', '{count} earlier action could not be verified and cannot be restored. An earlier instance key or damaged history may be the cause.', '{count} earlier actions could not be verified and cannot be restored. An earlier instance key or damaged history may be the cause.', host.history.availability.unavailableCount, { count: host.history.availability.unavailableCount }) }}
			</p>
			<p v-if="host.history.availability?.earlierScopeCount" role="status">
				{{ n('fadenstack_agent', '{count} earlier action uses another connection or previous app settings. Its saved content expires by {date}.', '{count} earlier actions use another connection or previous app settings. Their saved content expires by {date}.', host.history.availability.earlierScopeCount, { count: host.history.availability.earlierScopeCount, date: host.history.availability.earlierContentExpiresBy ? new Date(host.history.availability.earlierContentExpiresBy * 1000).toLocaleString() : '' }) }}
			</p>
			<p v-if="!host.historyCommandsAvailable" role="status">
				{{ t('fadenstack_agent', 'Action controls are unavailable on this version. Contact your administrator.') }}
			</p>
			<p v-if="host.history.loading" role="status">
				{{ t('fadenstack_agent', 'Loading actions…') }}
			</p>
			<p v-else-if="host.history.error" role="alert">
				{{ host.history.error }}
			</p>
			<p v-else-if="!host.history.items.length">
				{{ t('fadenstack_agent', 'No saved file changes are available for this account.') }}
			</p>
			<ul v-else>
				<li v-for="item in host.history.items" :key="item.id">
					<div>
						<strong>{{ item.name }}</strong>
						<span v-if="item.target">{{ item.target }}</span>
						<span>{{ state(item) }} · {{ new Date(item.createdAt * 1000).toLocaleString() }}</span>
					</div>
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
