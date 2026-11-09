<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { Icon } from '@fadenstack/ui-vue'
import type { AgentfileViewResults } from '../integrations/nextcloud/types.ts'
import { nativeFileLink } from '../integrations/nextcloud/fileReadResults.ts'
defineProps<{ result: AgentfileViewResults }>()
</script>

<template>
	<section class="fadenstack-definition" :aria-label="t('fadenstack_agent', 'Imported agent definition')">
		<div class="fadenstack-definition__name">
			<Icon name="page" :size="16" />
			<strong>{{ result.name }}</strong>
		</div>
		<p v-if="result.description">
			{{ result.description }}
		</p>
		<p class="fdn-muted">
			{{ t('fadenstack_agent', 'Read-only import · Native source permissions apply') }}
		</p>
		<ul v-if="result.sources.length">
			<li v-for="(source, index) in result.sources" :key="index">
				<a v-if="source.available && source.resourceId"
					:href="nativeFileLink(source.resourceId)"
					target="_blank"
					rel="noopener noreferrer">{{ source.name }}</a>
				<span v-else>{{ t('fadenstack_agent', 'Source unavailable under current access') }}</span>
			</li>
		</ul>
		<details v-if="result.instructions">
			<summary>{{ t('fadenstack_agent', 'Imported instructions (data only)') }}</summary>
			<p>{{ result.instructions }}</p>
		</details>
	</section>
</template>

<style scoped>
.fadenstack-definition { padding: 12px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 12px); font: inherit; color: inherit; overflow-wrap: anywhere; }

.fadenstack-definition__name { display: flex; gap: 8px; align-items: center; font: inherit; }

.fadenstack-definition p, .fadenstack-definition ul { margin-block: 8px; font: inherit; }

.fadenstack-definition ul { padding-inline-start: 20px; }

.fadenstack-definition details p { white-space: pre-wrap; }

.fadenstack-definition summary { cursor: pointer; }
</style>
