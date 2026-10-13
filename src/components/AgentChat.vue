<script setup lang="ts">
import { ref, watch, nextTick, onMounted, onScopeDispose } from 'vue'
import { t } from '@nextcloud/l10n'
import type { NextcloudAgent } from '../agent/createAgent.ts'
import { useObservable } from '../agent/useObservable.ts'
import { errorMessage } from '../integrations/nextcloud/files.ts'
import type { FileContext } from '../integrations/nextcloud/types.ts'
import TurnView from './TurnView.vue'
import AttachmentChip from './AttachmentChip.vue'
import ChatIcon from './ChatIcon.vue'
import mascot from '../../img/mascot.svg?url'
import '../styles/fadenstack.css'
import '../styles/nextcloud.css'

const props = defineProps<{ host: NextcloudAgent; context?: FileContext; loadingContext?: boolean; documentContext?: boolean }>()
const emit = defineEmits<{ 'signed-out': [] }>()
const chat = props.host.chat
const version = useObservable(chat)
const preparing = ref(false)
const problem = ref('')
const accountOpen = ref(false)
const toolbar = ref<HTMLElement>()
const composer = ref<HTMLTextAreaElement>()
const body = ref<HTMLElement>()
const turns = ref<HTMLElement>()
let stick = true
let observer: ResizeObserver | undefined

async function send(text = chat.input) {
	if (preparing.value || chat.isBusy || !text.trim()) return
	preparing.value = true
	problem.value = ''
	stick = true
	try { await props.host.send(text) } catch (error) { problem.value = errorMessage(error) } finally { preparing.value = false; await nextTick(); composer.value?.focus() }
}
function input(event: Event) { chat.setInput((event.target as HTMLTextAreaElement).value) }
function key(event: KeyboardEvent) {
	if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); void send() }
	if (event.key === 'Escape' && (chat.isBusy || preparing.value)) { event.preventDefault(); props.host.stop() }
}
function closeMenus() { chat.setModeMenuOpen(false); chat.setHistoryOpen(false); accountOpen.value = false }
function outside(event: PointerEvent) { if (event.target instanceof Node && !toolbar.value?.contains(event.target)) closeMenus() }
function dismiss(event: KeyboardEvent) { if (event.key === 'Escape') closeMenus() }
function summarize() {
	props.host.offerSelection(props.host.selection.snapshot())
	chat.setInput(t('fadenstack_agent', 'Summarize this document'))
	chat.requestComposerFocus()
}
function onScroll() { if (body.value) stick = body.value.scrollHeight - body.value.scrollTop - body.value.clientHeight < 60 }
async function grow() {
	await nextTick()
	if (composer.value) {
		composer.value.style.height = 'auto'
		composer.value.style.height = `${Math.min(composer.value.scrollHeight, Math.max(120, window.innerHeight / 3))}px`
	}
}
watch(version, grow)
onScopeDispose(chat.onComposerFocus(() => { composer.value?.focus(); composer.value?.setSelectionRange(chat.input.length, chat.input.length) }))
onMounted(() => {
	document.addEventListener('pointerdown', outside)
	document.addEventListener('keydown', dismiss)
	observer = new ResizeObserver(() => { if (stick && body.value) body.value.scrollTop = body.value.scrollHeight })
	if (turns.value) observer.observe(turns.value)
	void grow()
})
onScopeDispose(() => { observer?.disconnect(); document.removeEventListener('pointerdown', outside); document.removeEventListener('keydown', dismiss) })
</script>

<template>
	<div class="fdn-chat"
		data-theme="nextcloud"
		:data-version="version"
		@keydown.esc.stop="closeMenus">
		<header ref="toolbar" class="fdn-header">
			<div class="fdn-header-title" :title="chat.title">
				{{ chat.title }}
			</div>
			<div class="fdn-menu-anchor">
				<button type="button"
					class="fdn-mode-button"
					:aria-label="`${chat.strings.toolMode}: ${chat.toolModes.find(option => option.mode === chat.toolMode)?.label}`"
					:title="chat.toolModeHint"
					aria-haspopup="menu"
					:aria-expanded="chat.isModeMenuOpen"
					:disabled="!chat.isReady || chat.isBusy"
					@click="chat.setModeMenuOpen(!chat.isModeMenuOpen); chat.setHistoryOpen(false); accountOpen = false">
					<ChatIcon name="tool" :size="14" />{{ chat.toolModes.find(option => option.mode === chat.toolMode)?.label }}<ChatIcon name="chevron" :size="14" />
				</button>
				<div v-if="chat.isModeMenuOpen"
					class="fdn-menu"
					role="menu"
					:aria-label="chat.strings.toolMode">
					<button v-for="option in chat.toolModes"
						:key="option.mode"
						type="button"
						class="fdn-menu-item"
						role="menuitemradio"
						:aria-checked="option.mode === chat.toolMode"
						:disabled="!option.available"
						@click="chat.selectToolMode(option.mode)">
						<span class="fdn-menu-check"><ChatIcon v-if="option.mode === chat.toolMode" name="check" :size="14" /></span>
						<span><span class="fdn-menu-label">{{ option.label }}</span><span class="fdn-menu-description">{{ option.description }}</span></span>
					</button>
					<div class="fdn-menu-note">
						{{ chat.toolModeHint }}
					</div>
					<button type="button"
						role="menuitem"
						class="fdn-menu-item"
						@click="chat.resetApprovals(); closeMenus()">
						{{ chat.strings.resetApprovals }}
					</button>
				</div>
			</div>
			<div class="fdn-menu-anchor">
				<button type="button"
					class="fdn-icon-button"
					:title="chat.strings.history"
					:aria-label="chat.strings.history"
					aria-haspopup="menu"
					:aria-expanded="chat.isHistoryOpen"
					:disabled="!chat.isReady || chat.isBusy"
					@click="chat.toggleHistory(); chat.setModeMenuOpen(false); accountOpen = false">
					<ChatIcon name="history" />
				</button>
				<div v-if="chat.isHistoryOpen"
					class="fdn-menu fdn-history"
					role="menu"
					:aria-label="chat.strings.history">
					<div v-if="!chat.sessions.length" class="fdn-menu-note">
						{{ chat.strings.noHistory }}
					</div>
					<div v-for="session in chat.sessions"
						:key="session.id"
						class="fdn-history-item"
						:class="{ 'fdn-current': session.isCurrent }">
						<button type="button"
							role="menuitem"
							class="fdn-history-open"
							:disabled="chat.isBusy"
							@click="chat.openSession(session.id); closeMenus()">
							<span class="fdn-history-title">{{ session.title }}</span><span class="fdn-history-when">{{ session.whenText }}</span>
						</button>
						<button type="button"
							class="fdn-icon-button fdn-small"
							:aria-label="`${chat.strings.deleteSession}: ${session.title}`"
							:disabled="chat.isBusy"
							@click="chat.deleteSession(session.id)">
							<ChatIcon name="trash" :size="14" />
						</button>
					</div>
				</div>
			</div>
			<button type="button"
				class="fdn-icon-button"
				:title="chat.strings.newChat"
				:aria-label="chat.strings.newChat"
				:disabled="!chat.isReady || chat.isBusy || preparing"
				@click="chat.newChat(); chat.requestComposerFocus()">
				<ChatIcon name="plus" />
			</button>
			<div class="fdn-menu-anchor">
				<button type="button"
					class="fdn-icon-button"
					:title="t('fadenstack_agent', 'Account')"
					:aria-label="t('fadenstack_agent', 'Account')"
					aria-haspopup="menu"
					:aria-expanded="accountOpen"
					@click="accountOpen = !accountOpen; chat.setModeMenuOpen(false); chat.setHistoryOpen(false)">
					<ChatIcon name="account" />
				</button>
				<div v-if="accountOpen"
					class="fdn-menu"
					role="menu"
					:aria-label="t('fadenstack_agent', 'Account')">
					<div class="fdn-menu-note">
						{{ host.config.serverUrl }}<br>{{ host.config.agent }} · {{ t('fadenstack_agent', 'Connected') }}
					</div>
					<button type="button"
						role="menuitem"
						class="fdn-menu-item"
						@click="emit('signed-out')">
						{{ t('fadenstack_agent', 'Sign out') }}
					</button>
				</div>
			</div>
		</header>
		<div v-if="chat.signInRequired" class="fdn-banner" role="alert">
			{{ t('fadenstack_agent', 'Your sign-in expired. Sign in again.') }}<button type="button" class="fdn-link" @click="emit('signed-out')">
				{{ t('fadenstack_agent', 'Sign in') }}
			</button>
		</div>
		<div ref="body"
			class="fdn-body"
			role="log"
			aria-live="polite"
			aria-relevant="additions"
			@scroll="onScroll">
			<div ref="turns" class="fdn-turns">
				<div v-if="chat.isEmpty" class="fdn-empty">
					<div class="fdn-empty-mark">
						<img :src="mascot"
							width="64"
							height="64"
							alt="">
					</div>
					<p v-if="chat.isReady" class="fdn-greeting">
						{{ chat.greeting }}
					</p>
					<div v-if="chat.suggestions.length" class="fdn-suggestions">
						<button v-for="suggestion in chat.suggestions"
							:key="suggestion"
							type="button"
							class="fdn-suggestion"
							:disabled="chat.isBusy || preparing"
							@click="send(suggestion)">
							{{ suggestion }}
						</button>
					</div>
				</div>
				<button v-if="chat.hasEarlierTurns"
					type="button"
					class="fdn-earlier"
					@click="chat.loadEarlier()">
					{{ chat.strings.showEarlier }}
				</button>
				<TurnView v-for="turn in chat.turns"
					:key="turn.key"
					:turn="turn"
					:host="host" />
			</div>
		</div>
		<div v-if="problem || chat.error" class="fdn-banner" role="alert">
			{{ problem || chat.error }}
		</div>
		<div class="fdn-context-bar">
			<span v-if="loadingContext" class="fdn-status"><span class="fdn-spinner" />{{ t('fadenstack_agent', 'Reading file context…') }}</span>
			<a v-else-if="context"
				:href="context.file.url"
				target="_blank"
				rel="noopener noreferrer"
				:title="context.file.path"><ChatIcon name="page" :size="14" />{{ context.file.name }}</a>
			<span v-else class="fdn-muted">{{ t('fadenstack_agent', 'Select a file or folder to add context.') }}</span>
		</div>
		<div v-if="documentContext && context" class="fdn-context-bar fdn-muted">
			{{ t('fadenstack_agent', 'Saved document context. Save edits before sending.') }}
		</div>
		<div class="fdn-composer">
			<div v-if="chat.attachments.length" class="fdn-chips">
				<AttachmentChip v-for="attachment in chat.attachments"
					:key="attachment.key"
					:attachment="attachment"
					:disabled="preparing || chat.isBusy" />
			</div>
			<textarea id="fadenstack-message"
				ref="composer"
				:value="chat.input"
				rows="1"
				:aria-label="t('fadenstack_agent', 'Message')"
				:placeholder="chat.strings.composerPlaceholder"
				:disabled="preparing || chat.isBusy || loadingContext"
				@input="input"
				@keydown="key" />
			<div class="fdn-composer-bar">
				<div class="fdn-composer-accessory">
					<button v-if="context"
						type="button"
						class="fdn-icon-button fdn-small"
						:title="t('fadenstack_agent', 'Summarize this document')"
						:aria-label="t('fadenstack_agent', 'Summarize this document')"
						:disabled="chat.isBusy || preparing"
						@click="summarize">
						<ChatIcon name="page" :size="16" />
					</button>
					<span class="fdn-muted fdn-key-hint">{{ t('fadenstack_agent', 'Enter to send, Shift+Enter for a new line') }}</span>
				</div>
				<button v-if="chat.isBusy || preparing"
					type="button"
					class="fdn-send fdn-stop"
					:aria-label="chat.strings.stop"
					:title="`${chat.strings.stop} (Esc)`"
					@click="host.stop()">
					<ChatIcon name="stop" />
				</button>
				<button v-else
					type="button"
					class="fdn-send"
					:aria-label="chat.strings.send"
					:title="chat.strings.send"
					:disabled="!chat.canSend || loadingContext"
					@click="send()">
					<ChatIcon name="send" />
				</button>
			</div>
		</div>
	</div>
</template>
