<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AgentPanel from './AgentPanel.vue'
import type { Runtime } from '../agent/runtime.ts'
const props = defineProps<{ state: { open: boolean, loading: boolean, error: string, runtime?: Runtime, fileId?: number, documentName?: string, selectionEpoch: number } }>()
const emit = defineEmits<{ close: [] }>()
// Keep the native accessible heading/close control; the SDK owns the visible chat toolbar.
const chatHeader = computed(() => !props.state.loading && !props.state.error
	&& props.state.runtime?.config.enabled && props.state.runtime.config.serverUrl && props.state.runtime.config.agent
	&& props.state.runtime.state.signedIn && !!props.state.runtime.state.host)
</script>

<template>
	<NcAppSidebar class="fadenstack-drawer"
		:open="state.open"
		:name="t('fadenstack_agent', 'Fadenstack Agent')"
		:empty="!!chatHeader"
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
.fadenstack-drawer { position: fixed !important; inset-block-start: var(--header-height, 50px) !important; inset-block-end: 8px !important; inset-inline-end: 8px !important; height: calc(100dvh - var(--header-height, 50px) - 8px) !important; width: min(420px, calc(100vw - 16px)) !important; z-index: 1800 !important; border-radius: var(--border-radius-large); box-shadow: 0 4px 24px #0003; font-family: var(--font-face, system-ui); font-size: var(--default-font-size, 15px); }

.fadenstack-drawer-message { padding: 16px; }

body.fadenstack-workspace-child #header { display: none !important; }

body.fadenstack-workspace-child { --header-height: 0px; }

#fadenstack-native-workspace { position: fixed; z-index: 100; inset: var(--header-height, 50px) 0 8px; display: flex; flex-direction: column; background: var(--color-main-background); }

#fadenstack-native-frame { flex: 1; width: 100%; min-height: 0; border: 0; }

/* Native apps set display:flex on their root, overriding the UA [hidden] rule. */
.fadenstack-native-masked { display: none !important; }

.fadenstack-native-workspace-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 4px 12px; font: var(--default-font-size, 15px) / 1.5 var(--font-face, system-ui); }

.fadenstack-native-workspace-toolbar button { padding: 4px 8px; border: 1px solid var(--color-border); border-radius: var(--border-radius-element, 8px); background: var(--color-main-background); color: var(--color-main-text); font: inherit; cursor: pointer; }

.fadenstack-drawer .app-sidebar-tabs { flex: 1 1 0 !important; min-height: 0; overflow: hidden; }

.fadenstack-drawer .app-sidebar-tabs__content { height: 100%; min-height: 0 !important; box-sizing: border-box; }

.fadenstack-drawer .app-sidebar__tab { height: 100%; box-sizing: border-box; padding: 0 !important; overflow: hidden !important; }

/* Keep the Files toolbar and rows accessible alongside a desktop assistant. */
@media (width >= 1024px) {
	body.fadenstack-agent-open #fadenstack-native-workspace { inset-inline-end: 428px; }
	/* Notes nests app-content containers: reserve drawer space once, leaving
	 * native split panes/editors to use their original layout inside it. */
	body.fadenstack-agent-open #app-content-vue:not(#app-content-vue *, #app-content *),
	body.fadenstack-agent-open #app-content:not(#app-content-vue *, #app-content *) { margin-inline-end: 428px !important; }
	body.fadenstack-agent-open .app-mail > .floating-composer { margin-inline-end: 428px !important; max-width: calc(100vw - 452px); }
	body.fadenstack-agent-open.fadenstack-agent-in-viewer #viewer .modal-wrapper { max-width: calc(100% - 428px); margin-inline-end: 428px; }
}
</style>
