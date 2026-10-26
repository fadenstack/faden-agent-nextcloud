<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import { AgentAvatar, Icon } from '@fadenstack/ui-vue'
import type { FileContext } from '../integrations/nextcloud/types.ts'

const props = defineProps<{
	title: string
	serverUrl: string
	agent: string
	context?: FileContext
	documentContext?: boolean
}>()
const emit = defineEmits<{ close: [] }>()
const open = ref(true)
const contextHeading = useId()

watch(open, (value) => { if (!value) emit('close') })

const destination = computed(() => {
	try {
		const url = new URL(props.serverUrl)
		return /^https?:$/.test(url.protocol) && !url.username && !url.password
			? url.origin
			: t('fadenstack_agent', 'Connection unavailable')
	} catch { return t('fadenstack_agent', 'Connection unavailable') }
})

const nativeLink = computed(() => {
	if (!props.context) return undefined
	try {
		const url = new URL(props.context.file.url, location.origin)
		return url.origin === location.origin && /^https?:$/.test(url.protocol) && !url.username && !url.password
			? url.href
			: undefined
	} catch { return undefined }
})
</script>

<template>
	<NcDialog :open="open" :name="t('fadenstack_agent', 'Assistant details')" @update:open="open = $event">
		<div class="fadenstack-assistant-details">
			<div class="fadenstack-assistant-details__identity">
				<AgentAvatar :size="32" />
				<div>
					<p class="fadenstack-assistant-details__secondary">
						{{ t('fadenstack_agent', 'Fadenstack AI assistant') }}
					</p>
					<strong>{{ title }}</strong>
				</div>
			</div>
			<dl>
				<dt>{{ t('fadenstack_agent', 'Fadenstack server') }}</dt>
				<dd>{{ destination }}</dd>
				<dt>{{ t('fadenstack_agent', 'Published agent') }}</dt>
				<dd>{{ agent }}</dd>
			</dl>
			<section :aria-labelledby="contextHeading">
				<h3 :id="contextHeading">
					{{ t('fadenstack_agent', 'Current context') }}
				</h3>
				<template v-if="context">
					<p class="fadenstack-assistant-details__selection">
						<Icon :name="context.file.folder ? 'book' : 'page'" :size="20" />
						<strong>{{ context.file.name }}</strong>
					</p>
					<p class="fadenstack-assistant-details__path">
						{{ context.file.path }}
					</p>
					<p>{{ t('fadenstack_agent', 'Changing the selection does not change a response already in progress.') }}</p>
					<p v-if="documentContext && !context.file.folder">
						{{ t('fadenstack_agent', 'Only the saved document is available. Save your edits before sending a message.') }}
					</p>
					<NcButton v-if="nativeLink"
						:href="nativeLink"
						target="_blank"
						rel="noopener noreferrer"
						variant="tertiary"
						:aria-label="t('fadenstack_agent', 'Open context in Nextcloud')">
						<template #icon>
							<Icon :name="context.file.folder ? 'book' : 'page'" :size="20" />
						</template>
						{{ t('fadenstack_agent', 'Open in Nextcloud') }}
					</NcButton>
				</template>
				<p v-else>
					{{ t('fadenstack_agent', 'No file or folder context is attached.') }}
				</p>
			</section>
			<p>{{ t('fadenstack_agent', 'Access still depends on your current Nextcloud permissions. This dialog does not grant access.') }}</p>
		</div>
		<template #actions>
			<NcButton :aria-label="t('fadenstack_agent', 'Close assistant details')" @click="open = false">
				{{ t('fadenstack_agent', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.fadenstack-assistant-details {
	--fdn-agent-plate: var(--color-main-text);
	--fdn-agent-plate-side: var(--color-text-maxcontrast);
	--fdn-agent-eye: var(--color-main-background);
	--fdn-agent-pupil: var(--color-main-text);
	display: flex;
	flex-direction: column;
	gap: calc(4 * var(--default-grid-baseline, 4px));
	color: var(--color-main-text);
	font: var(--default-font-size, 15px) / 1.5 var(--font-face, system-ui);
	overflow-wrap: anywhere;
}

.fadenstack-assistant-details p { margin: 0; }

.fadenstack-assistant-details__identity {
	display: flex;
	align-items: center;
	gap: calc(3 * var(--default-grid-baseline, 4px));
}

.fadenstack-assistant-details__identity > :first-child { flex-shrink: 0; }

.fadenstack-assistant-details dl { margin: 0; }

.fadenstack-assistant-details dt,
.fadenstack-assistant-details__secondary,
.fadenstack-assistant-details__path {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.fadenstack-assistant-details dt:not(:first-child) { margin-block-start: calc(3 * var(--default-grid-baseline, 4px)); }

.fadenstack-assistant-details dd { margin: 0; }

.fadenstack-assistant-details section {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(2 * var(--default-grid-baseline, 4px));
}

.fadenstack-assistant-details h3 {
	margin: 0;
	font-size: inherit;
	font-weight: 600;
}

.fadenstack-assistant-details__selection {
	display: flex;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline, 4px));
}

.fadenstack-assistant-details__selection > :first-child { flex-shrink: 0; }
</style>
