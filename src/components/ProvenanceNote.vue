<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import { provenanceNote } from '../integrations/nextcloud/provenance.ts'
import type { ProvenanceSummary } from '../integrations/nextcloud/provenance.ts'

// Under a file write: whether the file is marked as AI-generated, and where, as the read-back after the write found
// it -- or why it is not.
const props = defineProps<{ summary: ProvenanceSummary, showExempt?: boolean }>()
const text = computed(() => {
	switch (provenanceNote(props.summary)) {
		case 'metadata-and-front-matter': return t('fadenstack_agent', 'Marked as AI-generated in the file\'s metadata and its front matter.')
		case 'metadata': return t('fadenstack_agent', 'Marked as AI-generated in the file\'s metadata.')
		case 'front-matter': return t('fadenstack_agent', 'Marked as AI-generated in its front matter only: Nextcloud did not keep the file\'s metadata.')
		case 'unsigned': return t('fadenstack_agent', 'Saved without an AI mark: the provenance service could not sign a record.')
		case 'not-kept': return t('fadenstack_agent', 'Saved without an AI mark: Nextcloud did not keep it.')
		default: return t('fadenstack_agent', 'No new AI mark was required by the agent\'s marking policy.')
	}
})
</script>

<template>
	<p v-if="!summary.exempt || showExempt"
		class="fdn-provenance-note fdn-muted"
		role="status"
		dir="auto">
		{{ text }}
	</p>
</template>
