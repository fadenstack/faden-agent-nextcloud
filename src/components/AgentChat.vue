<script setup lang="ts">
import { computed, h } from 'vue'
import { t } from '@nextcloud/l10n'
import { ApprovalPart, ToolCallPart } from '@fadenstack/ui'
import { ChatPanel, Icon, ToolCallView, type ChatTheme, type PartRenderer } from '@fadenstack/ui-vue'
import '@fadenstack/ui-web/styles.css'
import type { NextcloudAgent } from '../agent/createAgent.ts'
import { useObservable } from '../agent/useObservable.ts'
import { WRITE_TOOLS } from '../integrations/nextcloud/tools.ts'
import type { FileContext } from '../integrations/nextcloud/types.ts'
import NextcloudApproval from './NextcloudApproval.vue'
import SearchResultsCard from './SearchResultsCard.vue'
import '../styles/nextcloud.css'

const props = defineProps<{ host: NextcloudAgent; context?: FileContext; loadingContext?: boolean; documentContext?: boolean }>()
const emit = defineEmits<{ 'signed-out': [] }>()
const chat = props.host.chat
const version = useObservable(chat)
const composerContext = computed(() => { void version.value; return props.host.selection.snapshot() })
// The SDK's panel in Nextcloud's own colours (styles/nextcloud.css maps its tokens onto the user's theme).
const theme = 'nextcloud' as ChatTheme

// Nextcloud's parts of an answer: a file write shows the exact proposal and is registered before it is
// allowed; a search shows its results as cards above the tool line. Everything else is the SDK's own view.
const renderers: PartRenderer[] = [
	(part, strings) => {
		if (part instanceof ApprovalPart && WRITE_TOOLS.has(part.toolName) && part.request.arguments.proposalId) {
			return h(NextcloudApproval, { part, strings, host: props.host })
		}
		if (part instanceof ToolCallPart && part.source === 'host' && part.state === 'succeeded') {
			const result = props.host.toolResult(part.callId)
			if (result) return [h(SearchResultsCard, { result, host: props.host }), h(ToolCallView, { part, strings })]
		}
		return undefined
	},
]

function summarize() {
	props.host.offerSelection(props.host.selection.snapshot())
	chat.setInput(t('fadenstack_agent', 'Summarize this document'))
	chat.requestComposerFocus()
}
// Esc belongs to the panel: it closes a menu, or stops the answer (the composer stops it), but never also the
// Nextcloud drawer around it, which closes on Esc.
function escape(event: KeyboardEvent) {
	if (event.key !== 'Escape') return
	event.stopPropagation()
	if (chat.isSettingsOpen || chat.isModeMenuOpen || chat.isHistoryOpen) {
		chat.setSettingsOpen(false)
		chat.setModeMenuOpen(false)
		chat.setHistoryOpen(false)
	} else if (chat.isBusy) props.host.stop()
}
function signOut() {
	chat.setSettingsOpen(false)
	emit('signed-out')
}
</script>

<template>
	<ChatPanel class="fadenstack-chat"
		:chat="chat"
		:theme="theme"
		:part-renderers="renderers"
		@keydown="escape">
		<template #settings-extra>
			<div class="fdn-menu-note">
				{{ host.config.serverUrl }}<br>{{ host.config.agent }} · {{ t('fadenstack_agent', 'Connected') }}
			</div>
			<button type="button"
				role="menuitem"
				class="fdn-menu-item"
				@click="signOut">
				<span class="fdn-menu-check" />
				<span class="fdn-menu-label">{{ t('fadenstack_agent', 'Sign out') }}</span>
			</button>
		</template>
		<template #sign-in>
			<div class="fdn-banner" role="alert">
				{{ t('fadenstack_agent', 'Your sign-in expired. Sign in again.') }}
				<button type="button" class="fdn-link" @click="emit('signed-out')">
					{{ t('fadenstack_agent', 'Sign in') }}
				</button>
			</div>
		</template>
		<template #context-bar>
			<div class="fdn-context-bar" :data-version="version">
				<span v-if="loadingContext" class="fdn-status"><span class="fdn-spinner" />{{ t('fadenstack_agent', 'Reading file context…') }}</span>
				<a v-else-if="composerContext"
					:href="composerContext.file.url"
					target="_blank"
					rel="noopener noreferrer"
					:title="composerContext.file.path"><Icon name="page" :size="14" />{{ composerContext.file.name }}</a>
				<span v-else class="fdn-muted">{{ t('fadenstack_agent', 'Select a file or folder to add context.') }}</span>
			</div>
			<div v-if="documentContext && composerContext" class="fdn-context-bar fdn-muted">
				{{ t('fadenstack_agent', 'Saved document context. Save edits before sending.') }}
			</div>
		</template>
		<template #composer-accessory>
			<button v-if="composerContext"
				type="button"
				class="fdn-icon-button fdn-small"
				:title="t('fadenstack_agent', 'Summarize this document')"
				:aria-label="t('fadenstack_agent', 'Summarize this document')"
				:disabled="chat.isBusy"
				@click="summarize">
				<Icon name="page" :size="16" />
			</button>
		</template>
	</ChatPanel>
</template>
