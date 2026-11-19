<script setup lang="ts">
import { ref } from 'vue'
import { t } from '@nextcloud/l10n'
import type { ShareEntry, ShareOverviewResults } from '../integrations/nextcloud/types.ts'
import type { NextcloudAgent } from '../agent/createAgent.ts'
import { Icon } from '@fadenstack/ui-vue'
import { useObservable } from '../agent/useObservable.ts'
import { nativeFileLink } from '../integrations/nextcloud/fileReadResults.ts'

const props = defineProps<{ result: ShareOverviewResults, host: NextcloudAgent }>()
const version = typeof props.host?.chat?.subscribe === 'function' ? useObservable(props.host.chat) : ref(0)

function linkTarget(resourceId?: string): string | undefined {
	return nativeFileLink(resourceId)
}

function shareRecipient(share: ShareEntry): string {
	if (share.shareType === 3) {
		return t('fadenstack_agent', 'Public link')
	}
	return share.shareWithDisplayName || share.shareWith || t('fadenstack_agent', 'Unnamed recipient')
}

function shareTypeLabel(type: number): string {
	if (type === 0) {
		return t('fadenstack_agent', 'User')
	}
	if (type === 1) {
		return t('fadenstack_agent', 'Group')
	}
	if (type === 3) {
		return t('fadenstack_agent', 'Public link')
	}
	return t('fadenstack_agent', 'Share')
}

function formatPermissions(perm: number): string {
	if (perm === 1) {
		return t('fadenstack_agent', 'Read only')
	}
	const labels = [[1, t('fadenstack_agent', 'Read')], [2, t('fadenstack_agent', 'Edit')], [4, t('fadenstack_agent', 'Create')], [8, t('fadenstack_agent', 'Delete')], [16, t('fadenstack_agent', 'Share')]] as const
	return labels.filter(([bit]) => (perm & bit) !== 0).map(([, label]) => label).join(', ') || t('fadenstack_agent', 'No permissions')
}
</script>

<template>
	<section class="fdn-search-results fdn-shares-results" :data-version="version" :aria-label="t('fadenstack_agent', 'Shares and permissions')">
		<header class="fdn-search-title fdn-shares-title">
			<strong>{{ t('fadenstack_agent', 'Shares and permissions') }}</strong>
			<span v-if="result.resourceId" class="fdn-muted">
				<a v-if="linkTarget(result.resourceId)"
					:href="linkTarget(result.resourceId)"
					target="_blank"
					rel="noopener noreferrer">{{ t('fadenstack_agent', 'Open in Nextcloud') }}</a>
			</span>
		</header>

		<p class="fdn-muted fdn-shares-coverage" role="status">
			{{ t('fadenstack_agent', "Covers supported shares initiated by the current user. May include expired shares; this is not a complete access inventory.") }}
		</p>
		<details v-if="result.coverage" class="fdn-muted">
			<summary>{{ t('fadenstack_agent', 'Coverage details') }}</summary>
			<p>{{ result.coverage }}</p>
		</details>

		<p v-if="!result.shares.length" class="fdn-muted">
			{{ t('fadenstack_agent', 'No supported shares were returned within this coverage.') }}
		</p>

		<article v-for="share in result.shares" :key="share.id" class="fdn-search-file fdn-shares-entry">
			<div class="fdn-search-name">
				<Icon name="shield" :size="16" />
				<span>{{ shareRecipient(share) }}</span>
				<span class="fdn-shares-badge">{{ shareTypeLabel(share.shareType) }}</span>
				<span v-if="share.inherited" class="fdn-shares-badge fdn-muted">({{ t('fadenstack_agent', 'Inherited') }})</span>
			</div>
			<p class="fdn-muted">
				{{ formatPermissions(share.permissions) }}
				<template v-if="share.expiration">
					· {{ t('fadenstack_agent', 'Expires') }}: <time :datetime="share.expiration">{{ share.expiration }}</time>
				</template>
				<template v-if="share.path">
					· {{ share.path }}
				</template>
			</p>
		</article>
	</section>
</template>
