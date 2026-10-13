<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AgentPanel from './AgentPanel.vue'
import type { Runtime } from '../agent/runtime.ts'
defineProps<{ state: { open: boolean; loading: boolean; error: string; runtime?: Runtime; fileId?: number; documentName?: string; selectionEpoch: number } }>()
const emit = defineEmits<{ close: [] }>()
</script>
<template>
	<NcAppSidebar class="fadenstack-drawer"
		:open="state.open"
		name="Fadenstack Agent"
		:no-toggle="true"
		@keydown.esc.stop="emit('close')"
		@close="emit('close')">
		<NcAppSidebarTab id="fadenstack-assistant" :name="t('fadenstack_agent', 'Assistant')">
			<NcLoadingIcon v-if="state.loading" />
			<p v-else-if="state.error" class="fadenstack-drawer-message" role="alert">
				{{ state.error }}
			</p>
			<AgentPanel v-else-if="state.runtime && state.runtime.config.enabled && state.runtime.config.serverUrl && state.runtime.config.agent"
				:runtime="state.runtime"
				:file-id="state.fileId"
				:document-name="state.documentName"
				:selection-epoch="state.selectionEpoch" />
			<p v-else class="fadenstack-drawer-message">
				{{ t('fadenstack_agent', 'Ask your administrator to configure and enable Fadenstack Agent in Additional settings.') }}
			</p>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>
<style>
.fadenstack-drawer { position: fixed !important; inset-block-start: var(--header-height, 50px) !important; inset-block-end: 8px !important; inset-inline-end: 8px !important; height: calc(100dvh - var(--header-height, 50px) - 8px) !important; width: min(420px, calc(100vw - 16px)) !important; z-index: 1800 !important; border-radius: var(--border-radius-large); box-shadow: 0 4px 24px #0003; }

.fadenstack-drawer-message { padding: 16px; }

.fadenstack-drawer .app-sidebar-tabs { flex: 1 1 0 !important; min-height: 0; overflow: hidden; }

.fadenstack-drawer .app-sidebar-tabs__content { height: 100%; min-height: 0 !important; box-sizing: border-box; }

.fadenstack-drawer .app-sidebar__tab { height: 100%; box-sizing: border-box; padding: 0 !important; overflow: hidden !important; }

/* Keep the Files toolbar and rows accessible alongside a desktop assistant. */
@media (width >= 1024px) {
	body.fadenstack-agent-open #app-content-vue,
	body.fadenstack-agent-open #app-content { margin-inline-end: 428px !important; }
	body.fadenstack-agent-open.fadenstack-agent-in-viewer #viewer .modal-wrapper { max-width: calc(100% - 428px); margin-inline-end: 428px; }
}
</style>
