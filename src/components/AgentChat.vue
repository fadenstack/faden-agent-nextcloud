<script setup lang="ts">
import { computed, h, nextTick, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import { ApprovalPart, ToolCallPart } from '@fadenstack/ui'
import { AgentAvatar, ChatPanel, Icon, ToolCallView, type ChatTheme, type PartRenderer } from '@fadenstack/ui-vue'
import '@fadenstack/ui-web/styles.css'
import type { NextcloudAgent } from '../agent/createAgent.ts'
import { useObservable } from '../agent/useObservable.ts'
import { WRITE_TOOLS, HISTORY_TOOLS } from '../integrations/nextcloud/tools.ts'
import { EXTENDED_FILES_MUTATIONS } from '../integrations/nextcloud/filesExtended.ts'
import { CALENDAR_MUTATIONS, TASKS_MUTATIONS, CONTACTS_MUTATIONS } from '../integrations/nextcloud/caldav.ts'
import { TABLES_MUTATIONS } from '../integrations/nextcloud/tables.ts'
import { DECK_MUTATIONS } from '../integrations/nextcloud/deck.ts'
import { MAIL_MUTATIONS, TALK_MUTATIONS } from '../integrations/nextcloud/mailTalk.ts'
import type { FileContext, SearchViewResults } from '../integrations/nextcloud/types.ts'
import NextcloudApproval from './NextcloudApproval.vue'
import { CONTENT_MUTATIONS } from '../providers/contentTools.ts'
import SearchResultsCard from './SearchResultsCard.vue'
import FolderResultsCard from './FolderResultsCard.vue'
import SharesResultsCard from './SharesResultsCard.vue'
import AgentfileResultsCard from './AgentfileResultsCard.vue'
import AssistantDetails from './AssistantDetails.vue'
import ActionHistory from './ActionHistory.vue'
import EngineConnections from './EngineConnections.vue'
import '../styles/nextcloud.css'

const props = defineProps<{ host: NextcloudAgent; context?: FileContext; loadingContext?: boolean; documentContext?: boolean; contextError?: string }>()
const emit = defineEmits<{ 'signed-out': [] }>()
const chat = props.host.chat
const version = useObservable(chat)
const attachmentVersion = ref(0)
// A chip toggle notifies its own public view model, not the chat. Subscribe without proxying SDK objects.
watch(() => { void version.value; return props.host.contextAttachment }, (attachment, _previous, cleanup) => {
	attachmentVersion.value = attachment?.version ?? 0
	if (attachment) cleanup(attachment.subscribe(() => { attachmentVersion.value = attachment.version }))
}, { immediate: true })
const composerContext = computed(() => { void version.value; return props.host.selection.snapshot() })
const displayContext = computed(() => { void version.value; return chat.isBusy ? props.host.turnContext : composerContext.value })
const nativeContext = computed(() => {
	void version.value
	const app = props.host.workspace?.dependencies.guard.snapshot().app
	if (app === 'calendar') return t('fadenstack_agent', 'Calendar view · Ask about an event by name.')
	if (app === 'tasks') return t('fadenstack_agent', 'Tasks view · Ask about a task by name.')
	if (app === 'contacts') return t('fadenstack_agent', 'Contacts view · Ask about a contact by name.')
	if (app === 'tables') return t('fadenstack_agent', 'Tables view · Ask about this table or a row by its content.')
	if (app === 'deck') return t('fadenstack_agent', 'Deck view · Ask about a card by name.')
	if (app === 'mail') return t('fadenstack_agent', 'Mail view · Read messages or prepare a draft. Sending needs your approval.')
	if (app === 'spreed') return t('fadenstack_agent', 'Talk view · Ask about this conversation or prepare a message.')
	if (app === 'collectives') return t('fadenstack_agent', 'Collectives view · Read the page hierarchy or prepare a page change. Body editing stays in the native app.')
	if (app === 'forms') return t('fadenstack_agent', 'Forms view · Prepare a private form or review a response before submitting it.')
	if (app === 'notes') return t('fadenstack_agent', 'Notes view · Read notes or create a new one. Switch native editors to Preview before an automatic refresh.')
	if (app === 'bookmarks') return t('fadenstack_agent', 'Bookmarks view · Read saved links or prepare a bookmark. Native URL fetching needs your approval.')
	if (app === 'flow') return t('fadenstack_agent', 'Personal Flow · Review blocked drafts or approve a rule change.')
	return ''
})
const contextLabel = computed(() => {
	void version.value
	void attachmentVersion.value
	if (chat.isBusy && props.host.history.active) return t('fadenstack_agent', 'Action')
	if (chat.isBusy) return t('fadenstack_agent', 'This response')
	if (composerContext.value && !props.host.selectionIncluded) return t('fadenstack_agent', 'Not included')
	return t('fadenstack_agent', 'Next message')
})
const detailsOpen = ref(false)
const actionsOpen = ref(false)
const engineConnectionsOpen = ref(false)
const detailsButton = ref<HTMLButtonElement>()
let detailsTrigger: HTMLElement | undefined
function openDetails(event: Event) {
	detailsTrigger = event.currentTarget instanceof HTMLElement ? event.currentTarget : undefined
	detailsOpen.value = true
}
async function closeDetails() {
	detailsOpen.value = false
	await nextTick()
	if (detailsTrigger?.isConnected) detailsTrigger.focus()
	else detailsButton.value?.focus()
}
// The SDK's panel in Nextcloud's own colours (styles/nextcloud.css maps its tokens onto the user's theme).
const theme = 'nextcloud' as ChatTheme

// Nextcloud's parts of an answer: a file write shows the exact proposal and is registered before it is
// allowed; a search shows its results as cards above the tool line. Everything else is the SDK's own view.
const renderers: PartRenderer[] = [
	(part, strings) => {
		if (part instanceof ApprovalPart && (((WRITE_TOOLS.has(part.toolName) || EXTENDED_FILES_MUTATIONS.has(part.toolName) || CALENDAR_MUTATIONS.has(part.toolName) || TASKS_MUTATIONS.has(part.toolName) || CONTACTS_MUTATIONS.has(part.toolName) || TABLES_MUTATIONS.has(part.toolName) || DECK_MUTATIONS.has(part.toolName) || MAIL_MUTATIONS.has(part.toolName) || TALK_MUTATIONS.has(part.toolName) || CONTENT_MUTATIONS.has(part.toolName)) && part.request.arguments.proposalId) || (HISTORY_TOOLS.has(part.toolName) && part.request.arguments.historyPreviewId))) {
			return h(NextcloudApproval, { part, strings, host: props.host })
		}
		if (part instanceof ToolCallPart && part.source === 'host' && part.state === 'succeeded') {
			const result = props.host.toolResult(part)
			if (result) {
				if (result.kind === 'nextcloud-agentfile-result') {
					return [h(AgentfileResultsCard, { result }), h(ToolCallView, { part, strings })]
				}
				if (result.kind === 'nextcloud-folder-overview') {
					return [h(FolderResultsCard, { result, host: props.host }), h(ToolCallView, { part, strings })]
				}
				if (result.kind === 'nextcloud-share-overview') {
					return [h(SharesResultsCard, { result, host: props.host }), h(ToolCallView, { part, strings })]
				}
				return [h(SearchResultsCard, { result: result as SearchViewResults, host: props.host }), h(ToolCallView, { part, strings })]
			}
		}
		return undefined
	},
]

function summarize() {
	if (chat.isBusy) return
	props.host.offerSelection(props.host.selection.snapshot())
	chat.setInput(t('fadenstack_agent', 'Summarize this document'))
	chat.requestComposerFocus()
}
// Esc belongs to the panel: it closes a menu, or stops the answer (the composer stops it), but never also the
// Nextcloud drawer around it, which closes on Esc.
function escape(event: KeyboardEvent) {
	if (event.key !== 'Escape') return
	event.stopPropagation()
	if (detailsOpen.value) void closeDetails()
	else if (engineConnectionsOpen.value) engineConnectionsOpen.value = false
	else if (actionsOpen.value) actionsOpen.value = false
	else if (chat.isSettingsOpen || chat.isModeMenuOpen || chat.isHistoryOpen) {
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
	<div class="fadenstack-chat-container">
		<ChatPanel class="fadenstack-chat"
			:chat="chat"
			:theme="theme"
			:part-renderers="renderers"
			@keydown="escape">
			<template #header-extra>
				<button v-if="host.historyAvailable"
					type="button"
					class="fdn-icon-button"
					:aria-label="t('fadenstack_agent', 'Agent actions')"
					:title="t('fadenstack_agent', 'Agent actions')"
					:disabled="chat.isBusy"
					:aria-expanded="actionsOpen"
					aria-haspopup="dialog"
					@click="actionsOpen = true">
					<Icon name="page" :size="20" />
				</button>
				<button ref="detailsButton"
					type="button"
					class="fdn-icon-button"
					:aria-label="t('fadenstack_agent', 'Assistant details')"
					:aria-expanded="detailsOpen"
					aria-haspopup="dialog"
					:title="t('fadenstack_agent', 'Assistant details')"
					@click="openDetails">
					<AgentAvatar :size="24" />
				</button>
			</template>
			<template #settings-extra>
				<div class="fdn-menu-note">
					{{ host.config.serverUrl }}<br>{{ host.config.agent }} · {{ t('fadenstack_agent', 'Connected') }}
				</div>
				<button v-if="host.files.nativeProviders?.engineConnections"
					type="button"
					role="menuitem"
					class="fdn-menu-item"
					:disabled="chat.isBusy"
					@click="chat.setSettingsOpen(false); engineConnectionsOpen = true">
					<span class="fdn-menu-check" />
					<span class="fdn-menu-label">{{ t('fadenstack_agent', 'Workflow connections') }}</span>
				</button>
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
				<p v-if="contextError" class="fdn-banner fadenstack-context-error" role="alert">
					{{ contextError }}
				</p>
				<div class="fdn-context-bar fadenstack-context" :data-version="version">
					<span class="fadenstack-context-label">{{ contextLabel }}</span>
					<span v-if="loadingContext && !chat.isBusy" class="fdn-status"><span class="fdn-spinner" />{{ t('fadenstack_agent', 'Checking file context…') }}</span>
					<span v-else-if="host.history.active" class="fadenstack-context-target">{{ host.history.target || t('fadenstack_agent', 'Checking saved change…') }}</span>
					<button v-else-if="displayContext"
						type="button"
						class="fadenstack-context-target"
						:title="displayContext.file.path"
						:aria-label="t('fadenstack_agent', 'Context details: {name}', { name: displayContext.file.name })"
						@click="openDetails">
						<Icon :name="displayContext.file.folder ? 'book' : 'page'" :size="16" />
						<span>{{ displayContext.file.name }}</span>
					</button>
					<span v-else-if="nativeContext" class="fdn-muted">{{ nativeContext }}</span>
					<span v-else class="fdn-muted">{{ chat.isBusy ? t('fadenstack_agent', 'No file included in this response.') : t('fadenstack_agent', 'Search files or choose a file in Files.') }}</span>
				</div>
				<div v-if="documentContext && displayContext" class="fdn-context-bar fdn-muted fadenstack-context-note">
					{{ displayContext.file.name.toLowerCase().endsWith('.whiteboard') ? t('fadenstack_agent', 'Saved Whiteboard scene. Unsaved canvas edits are excluded.') : t('fadenstack_agent', 'Saved document context. Save edits before sending.') }}
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
		<ActionHistory v-if="actionsOpen" :host="host" @close="actionsOpen = false" />
		<EngineConnections v-if="engineConnectionsOpen && host.files.nativeProviders"
			:api="host.files.nativeProviders"
			@close="engineConnectionsOpen = false; chat.requestComposerFocus()" />
		<AssistantDetails v-if="detailsOpen"
			:title="chat.title"
			:server-url="host.config.serverUrl"
			:agent="host.config.agent"
			:context="displayContext"
			:document-context="documentContext"
			@close="closeDetails" />
	</div>
</template>

<style>
.fadenstack-chat-container { display: flex; flex-direction: column; min-height: 0; height: 100%; }

.fadenstack-panel .fadenstack-context { display: flex; align-items: center; flex-wrap: wrap; gap: 4px 8px; padding: 8px 14px; }

.fadenstack-context-label { color: var(--color-text-maxcontrast); font-size: var(--font-size-small, 13px); flex-shrink: 0; }

.fadenstack-panel .fadenstack-context-target { display: inline-flex; align-items: center; gap: 6px; min-width: 0; max-width: 100%; border: 0; padding: 4px; background: transparent; color: var(--color-primary-element); text-align: start; }

.fadenstack-context-target span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.fadenstack-panel .fadenstack-context-note { padding-block-start: 0; font-size: var(--font-size-small, 13px); }
</style>
