<script setup lang="ts">
import type { AttachmentViewModel } from '@fadenstack/ui'
import { t } from '@nextcloud/l10n'
import ChatIcon from './ChatIcon.vue'
import { useObservable } from '../agent/useObservable.ts'
const props = defineProps<{ attachment: AttachmentViewModel; disabled: boolean }>()
const version = useObservable(props.attachment)
</script>
<template>
	<div class="fdn-chip" :data-version="version" :class="{ 'fdn-chip-off': !attachment.sends }">
		<button type="button"
			class="fdn-chip-toggle"
			:disabled="disabled"
			:aria-pressed="attachment.sends"
			:title="t('fadenstack_agent', 'Include or remove file context')"
			@click="attachment.toggle()">
			<ChatIcon name="page" :size="14" /><span class="fdn-chip-title" :title="attachment.subtitle">{{ attachment.title }}</span><ChatIcon :name="attachment.sends ? 'check' : 'plus'" :size="14" />
		</button>
	</div>
</template>
<style>
.fadenstack-attachment { padding: 6px; border: 1px solid var(--color-border); border-radius: 8px; }

.fadenstack-attachment-off { opacity: .65; }
</style>
