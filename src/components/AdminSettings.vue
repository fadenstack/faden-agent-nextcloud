<script setup lang="ts">
import { reactive, ref } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import type { AppConfig } from '../integrations/nextcloud/types.ts'
import { errorMessage } from '../integrations/nextcloud/files.ts'
const config = reactive(loadState<AppConfig>('fadenstack_agent', 'admin-config'))
const message = ref('')
const saving = ref(false)
async function save() {
	saving.value = true
	try { await axios.put(generateUrl('/apps/fadenstack_agent/config'), config); message.value = t('fadenstack_agent', 'Saved. Reload Files to use the new configuration.') } catch (error) { message.value = errorMessage(error) } finally { saving.value = false }
}
</script>
<template>
	<form class="fadenstack-settings" @submit.prevent="save">
		<h2>Fadenstack Agent</h2>
		<label>{{ t('fadenstack_agent', 'Enable integration') }}<input v-model="config.enabled" type="checkbox"></label>
		<label>{{ t('fadenstack_agent', 'Fadenstack server origin') }}<input v-model="config.serverUrl"
			type="url"
			required
			placeholder="https://ai.example.org"></label>
		<label>{{ t('fadenstack_agent', 'Published agent identifier') }}<input v-model="config.agent" required pattern="[a-zA-Z0-9_-]{1,100}"></label>
		<label><input v-model="config.development" type="checkbox" aria-describedby="fadenstack-development-warning">{{ t('fadenstack_agent', 'Experimental: development mode') }}</label>
		<NcNoteCard id="fadenstack-development-warning"
			type="warning"
			:text="t('fadenstack_agent', 'Mail, Talk and the workflow engines are untested in this mode and send content to other people. It also allows HTTP on localhost. Use it for testing only, never in production.')" />
		<p>{{ t('fadenstack_agent', 'Configure the Nextcloud origin in Fadenstack CORS. Only the configured server origin is allowed by this app.') }}</p>
		<NcButton type="submit" variant="primary" :disabled="saving">
			{{ t('fadenstack_agent', 'Save') }}
		</NcButton>
		<p role="status">
			{{ message }}
		</p>
	</form>
</template>
<style>
.fadenstack-settings { margin: 30px; max-width: 650px; }

.fadenstack-settings label { display: block; margin: 14px 0; }

.fadenstack-settings input:not([type=checkbox]) { display: block; width: 100%; }
</style>
