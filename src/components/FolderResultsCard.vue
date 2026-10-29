<script setup lang="ts">
import { ref } from 'vue'
import { t } from '@nextcloud/l10n'
import type { FolderOverviewResults, FolderListingEntry } from '../integrations/nextcloud/types.ts'
import { nativeFileLink } from '../integrations/nextcloud/fileReadResults.ts'
import type { NextcloudAgent } from '../agent/createAgent.ts'
import { errorMessage } from '../integrations/nextcloud/files.ts'
import { Icon } from '@fadenstack/ui-vue'
import { useObservable } from '../agent/useObservable.ts'

const props = defineProps<{ result: FolderOverviewResults; host: NextcloudAgent }>()
const version = typeof props.host?.chat?.subscribe === 'function' ? useObservable(props.host.chat) : ref(0)
const problem = ref('')
const adding = ref('')

function link(entry: FolderListingEntry): string | undefined {
	return nativeFileLink(entry.resourceId)
}

function formatSize(bytes?: number): string {
	if (bytes === undefined || bytes === null || !Number.isFinite(bytes) || bytes < 0) return ''
	if (bytes < 1024) return `${bytes} B`
	if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
	return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function formatTime(timestamp?: number): string {
	if (!timestamp || !Number.isFinite(timestamp)) return ''
	try {
		return new Intl.DateTimeFormat(props.host.config.locale ?? navigator.language, { dateStyle: 'medium' }).format(new Date(timestamp * 1000))
	} catch {
		return ''
	}
}

function dateValue(timestamp?: number): string | undefined {
	if (!timestamp || !Number.isFinite(timestamp)) return undefined
	const date = new Date(timestamp * 1000)
	return Number.isFinite(date.getTime()) ? date.toISOString() : undefined
}

async function attach(entry: FolderListingEntry) {
	if (!entry.resourceId) return
	adding.value = entry.resourceId
	problem.value = ''
	try {
		await props.host.addSearchContext(entry.resourceId)
	} catch (error) {
		problem.value = errorMessage(error)
	} finally {
		adding.value = ''
	}
}
</script>

<template>
	<section class="fdn-search-results fdn-folder-results" :data-version="version" :aria-label="t('fadenstack_agent', 'Folder contents')">
		<header class="fdn-search-title fdn-folder-title">
			<strong>{{ t('fadenstack_agent', 'Folder contents') }}: {{ result.directory }}</strong>
			<span v-if="result.entries.length" class="fdn-muted">{{ result.entries.length }} {{ result.entries.length === 1 ? t('fadenstack_agent', 'item') : t('fadenstack_agent', 'items') }}</span>
		</header>

		<p v-if="!result.entries.length" class="fdn-muted">
			{{ result.partial ? t('fadenstack_agent', 'No entries returned within this listing’s coverage.') : t('fadenstack_agent', 'Folder is empty.') }}
		</p>

		<article v-for="entry in result.entries" :key="entry.resourceId ?? entry.href ?? entry.filename" class="fdn-search-file fdn-folder-entry">
			<div class="fdn-search-name">
				<span v-if="entry.folder" aria-hidden="true">📁</span>
				<Icon v-else name="page" :size="16" />
				<a v-if="link(entry)"
					:href="link(entry)"
					target="_blank"
					rel="noopener noreferrer">{{ entry.filename }}</a>
				<strong v-else>{{ entry.filename }}</strong>
				<span v-if="entry.folder" class="fdn-muted">({{ t('fadenstack_agent', 'Folder') }})</span>
			</div>
			<p class="fdn-muted">
				<template v-if="!entry.folder && entry.size !== undefined">
					{{ formatSize(entry.size) }}
				</template>
				<template v-if="dateValue(entry.modified)">
					<span v-if="!entry.folder && entry.size !== undefined">&nbsp;·&nbsp;</span>
					<time :datetime="dateValue(entry.modified)">{{ formatTime(entry.modified) }}</time>
				</template>
			</p>
			<div v-if="!entry.folder && entry.resourceId" class="fdn-search-actions">
				<a v-if="link(entry)"
					:href="link(entry)"
					target="_blank"
					rel="noopener noreferrer">{{ t('fadenstack_agent', 'Open in Nextcloud') }}</a>
				<button type="button"
					:aria-label="`${t('fadenstack_agent', 'Add to context')}: ${entry.filename}`"
					:disabled="!!adding || host.chat.isBusy"
					@click="attach(entry)">
					{{ t('fadenstack_agent', 'Add to context') }}
				</button>
			</div>
		</article>

		<p v-if="result.partial" class="fdn-muted" role="status">
			{{ t('fadenstack_agent', 'Partial folder listing. Some entries were excluded by policy or limit.') }}
		</p>

		<p v-if="problem" role="alert">
			{{ problem }}
		</p>
	</section>
</template>
