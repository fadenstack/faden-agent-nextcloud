<script setup lang="ts">
import { ref, computed } from 'vue'
import { t } from '@nextcloud/l10n'
import type { SearchViewResults, FileResource } from '../integrations/nextcloud/types.ts'
import type { NextcloudAgent } from '../agent/createAgent.ts'
import { errorMessage } from '../integrations/nextcloud/files.ts'
import { Icon } from '@fadenstack/ui-vue'
import { useObservable } from '../agent/useObservable.ts'

const props = defineProps<{ result: SearchViewResults; host: NextcloudAgent }>()
const version = useObservable(props.host.chat)
const problem = ref('')
const adding = ref('')
const files = computed(() => props.result.kind === 'nextcloud-document-search' ? [props.result.file] : props.result.results)
function link(file: FileResource) {
	try {
		const url = new URL(file.web_url, location.origin)
		return url.origin === location.origin && /^https?:$/.test(url.protocol) ? url.href : undefined
	} catch { return undefined }
}
function modified(date: string | null) {
	return date && Number.isFinite(Date.parse(date)) ? new Intl.DateTimeFormat(props.host.config.locale ?? navigator.language, { dateStyle: 'medium' }).format(new Date(date)) : t('fadenstack_agent', 'Date unavailable')
}
async function attach(file: FileResource) {
	adding.value = file.resource_id
	problem.value = ''
	try { await props.host.addSearchContext(file.resource_id) } catch (error) { problem.value = errorMessage(error) } finally { adding.value = '' }
}
</script>
<template>
	<section class="fdn-search-results" :data-version="version" :aria-label="t('fadenstack_agent', 'Search results')">
		<header class="fdn-search-title">
			<strong>{{ result.search_mode === 'filenames' ? t('fadenstack_agent', 'Filename matches') : result.search_mode === 'document' ? t('fadenstack_agent', 'Document passages') : result.search_mode === 'bounded-content' ? t('fadenstack_agent', 'Text content matches · bounded scan') : t('fadenstack_agent', 'Indexed content matches') }}</strong>
			<span class="fdn-muted">{{ result.duration_ms }} ms</span>
		</header>
		<p v-if="result.kind === 'nextcloud-search-results' && result.available === false" role="status">
			{{ t('fadenstack_agent', 'Indexed document search is unavailable on this server. Search filenames or an attached document instead.') }}
		</p>
		<p v-else-if="!files.length" class="fdn-muted">
			{{ t('fadenstack_agent', 'No matching files.') }}
		</p>
		<article v-for="file in files" :key="file.resource_id" class="fdn-search-file">
			<div class="fdn-search-name">
				<span v-if="file.is_directory" aria-hidden="true">📁</span><Icon v-else name="page" :size="16" />
				<a v-if="link(file)"
					:href="link(file)"
					target="_blank"
					rel="noopener noreferrer">{{ file.name }}</a><strong v-else>{{ file.name }}</strong>
			</div>
			<p class="fdn-muted">
				{{ file.path }} · <time :datetime="file.modified_at ?? undefined">{{ modified(file.modified_at) }}</time>
			</p>
			<p v-if="file.excerpt" class="fdn-search-excerpt">
				{{ file.excerpt }}
			</p>
			<div class="fdn-search-actions">
				<a v-if="link(file)"
					:href="link(file)"
					target="_blank"
					rel="noopener noreferrer">{{ t('fadenstack_agent', 'Open in Nextcloud') }}</a>
				<button type="button"
					:aria-label="`${t('fadenstack_agent', 'Add to context')}: ${file.name}`"
					:disabled="!!adding || host.chat.isBusy"
					@click="attach(file)">
					{{ t('fadenstack_agent', 'Add to context') }}
				</button>
			</div>
		</article>
		<template v-if="result.kind === 'nextcloud-document-search'">
			<p class="fdn-muted">
				{{ result.total_matches === null ? t('fadenstack_agent', 'Matches in processed text') : t('fadenstack_agent', 'Total matches') }}: {{ result.total_matches ?? result.processed_match_count }}
			</p>
			<p v-if="!result.matches.length">
				{{ t('fadenstack_agent', 'No matching passages in the processed text.') }}
			</p>
			<blockquote v-for="(match, index) in result.matches" :key="index" class="fdn-search-passage">
				<span class="fdn-muted">{{ match.page ? t('fadenstack_agent', 'Page') : t('fadenstack_agent', 'Line') }} {{ match.page ?? match.line }}</span>
				<p>{{ match.excerpt }}</p>
			</blockquote>
			<p v-if="result.matches_truncated" class="fdn-muted">
				{{ t('fadenstack_agent', 'More matches exist than are shown.') }}
			</p>
		</template>
		<p v-if="result.partial" class="fdn-muted" role="status">
			{{ t('fadenstack_agent', 'Partial results. Narrow the search or request the next available page.') }}
		</p>
		<p v-if="problem" role="alert">
			{{ problem }}
		</p>
	</section>
</template>
