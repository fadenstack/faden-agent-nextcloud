<script setup lang="ts">
import { ref, watch, onMounted, onUnmounted } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import type { Runtime } from '../agent/runtime.ts'
import { errorMessage } from '../integrations/nextcloud/files.ts'
import type { FileContext } from '../integrations/nextcloud/types.ts'
import AgentChat from './AgentChat.vue'
import mascot from '../../img/mascot.svg?url'

const props = defineProps<{ runtime: Runtime; fileId?: number; documentName?: string; selectionEpoch?: number }>()
const email = ref('')
const password = ref('')
const busy = ref(false)
const problem = ref('')
const selected = ref<FileContext>()
const loadingContext = ref(false)
let selectionRevision = 0

async function select(id?: number) {
	const revision = ++selectionRevision
	problem.value = ''
	selected.value = undefined
	loadingContext.value = false
	props.runtime.state.host?.offerSelection()
	if (!id) { props.runtime.state.host?.offerSelection(); return }
	loadingContext.value = true
	try {
		const context = await props.runtime.ensure().selection.select(id)
		if (revision === selectionRevision && props.documentName && (!context || context.file.folder || context.file.name !== props.documentName)) {
			props.runtime.state.host?.offerSelection()
			throw new Error(t('fadenstack_agent', 'The active document changed. Reopen the assistant to attach it.'))
		}
		if (revision === selectionRevision) { selected.value = context; props.runtime.state.host?.offerSelection(context) }
	} catch (error) { if (revision === selectionRevision) problem.value = errorMessage(error) } finally { if (revision === selectionRevision) loadingContext.value = false }
}
watch(() => [props.fileId, props.selectionEpoch] as const, ([id]) => select(id), { immediate: true })
watch(() => props.runtime.state.host, (host) => { if (host) void select(props.fileId); else selected.value = undefined })

async function signIn() {
	busy.value = true
	problem.value = ''
	const secret = password.value
	password.value = ''
	try {
		await props.runtime.ensure().signIn(email.value.trim(), secret)
		props.runtime.connected()
	} catch (error) {
		problem.value = errorMessage(error)
		props.runtime.clear()
	} finally { busy.value = false }
}
const clear = () => props.runtime.clear()
onMounted(() => { void props.runtime.verify() })
onUnmounted(() => { ++selectionRevision })
</script>

<template>
	<section class="fadenstack-panel" :aria-label="t('fadenstack_agent', 'Fadenstack Agent')">
		<header v-if="!runtime.state.signedIn">
			<img :src="mascot"
				width="32"
				height="32"
				alt=""
				aria-hidden="true">
			<span>{{ runtime.config.agent }}</span>
			<p>{{ runtime.state.signedIn ? t('fadenstack_agent', 'Connected') : t('fadenstack_agent', 'Sign in to Fadenstack') }}</p>
		</header>
		<div class="fadenstack-panel-body">
			<p v-if="problem" role="alert">
				{{ problem }}
			</p>
			<form v-if="!runtime.state.signedIn" class="fadenstack-signin" @submit.prevent="signIn">
				<p>{{ runtime.config.serverUrl }}</p>
				<label for="fadenstack-email">{{ t('fadenstack_agent', 'Email') }}</label>
				<input id="fadenstack-email"
					v-model="email"
					type="text"
					autocomplete="username"
					required
					:disabled="busy">
				<label for="fadenstack-password">{{ t('fadenstack_agent', 'Password') }}</label>
				<input id="fadenstack-password"
					v-model="password"
					type="password"
					autocomplete="current-password"
					required
					:disabled="busy">
				<NcButton type="submit" variant="primary" :disabled="busy">
					{{ t('fadenstack_agent', 'Sign in') }}
				</NcButton>
				<NcLoadingIcon v-if="busy" />
			</form>
			<template v-else-if="runtime.state.host">
				<AgentChat :key="runtime.state.host.config.binding + runtime.state.host.tokens.deviceId"
					:host="runtime.state.host"
					:context="selected"
					:loading-context="loadingContext"
					:document-context="!!documentName"
					@signed-out="clear" />
			</template>
		</div>
	</section>
</template>

<style>
.fadenstack-panel { padding: 0; display: flex; flex-direction: column; gap: 8px; color: var(--color-main-text); background: var(--color-main-background); min-width: 0; min-height: 0; height: 100%; box-sizing: border-box; }

.fadenstack-panel > header { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; padding: 16px; }

.fadenstack-panel-body { display: flex; flex-direction: column; flex: 1; min-height: 0; gap: 8px; }

.fadenstack-panel p { margin: 4px 0; }

.fadenstack-panel small { display: block; color: var(--color-text-maxcontrast); overflow-wrap: anywhere; }

.fadenstack-panel pre { white-space: pre-wrap; overflow-wrap: anywhere; font-family: monospace; max-height: 350px; overflow-y: auto; padding: 8px; background: var(--color-background-dark); border-radius: 6px; }

.fadenstack-panel a { color: var(--color-primary-element); text-decoration: underline; overflow-wrap: anywhere; }

.fadenstack-signin { display: flex; flex-direction: column; gap: 8px; padding: 16px; }

.fadenstack-panel input:not([type=checkbox]), .fadenstack-panel textarea { width: 100%; box-sizing: border-box; }

.fadenstack-panel label { display: block; }
</style>
