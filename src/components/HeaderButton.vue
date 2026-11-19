<script setup lang="ts">
import NcButton from '@nextcloud/vue/components/NcButton'
import { t } from '@nextcloud/l10n'
defineProps<{ state: { open: boolean } }>()
const emit = defineEmits<{ toggle: [] }>()
</script>

<template>
	<NcButton class="fadenstack-header-button"
		variant="tertiary"
		:aria-label="t('fadenstack_agent', 'Fadenstack Agent')"
		:aria-expanded="state.open"
		aria-controls="fadenstack-agent-drawer"
		:title="t('fadenstack_agent', 'Fadenstack Agent')"
		@click="emit('toggle')">
		<template #icon>
			<span class="fadenstack-header-icon" aria-hidden="true" />
		</template>
	</NcButton>
</template>

<style>
.fadenstack-header-button { color: var(--color-header-text) !important; }

.fadenstack-header-icon { display: inline-block; width: 24px; height: 24px; background: currentcolor; mask-image: url('../../img/app.svg'); mask-size: contain; mask-position: center; mask-repeat: no-repeat; }

.fadenstack-viewer-button { display: flex; align-items: center; }

.fadenstack-viewer-button .fadenstack-header-button { color: inherit !important; }
</style>
