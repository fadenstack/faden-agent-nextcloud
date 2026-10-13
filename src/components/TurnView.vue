<script setup lang="ts">
import type { TurnViewModel } from '@fadenstack/ui'
import type { NextcloudAgent } from '../agent/createAgent.ts'
import { useObservable } from '../agent/useObservable.ts'
import PartView from './PartView.vue'
import ChatIcon from './ChatIcon.vue'
import { t } from '@nextcloud/l10n'
const props = defineProps<{ turn: TurnViewModel; host: NextcloudAgent }>()
const version = useObservable(props.turn)
</script>
<template>
	<article class="fdn-turn" :data-version="version">
		<div class="fdn-user">
			<p class="fdn-user-bubble">
				{{ turn.userText }}
			</p>
		</div>
		<div class="fdn-chips">
			<span v-for="(attachment, index) in turn.attachments" :key="index" class="fdn-chip fdn-chip-static"><ChatIcon name="page" :size="14" />{{ attachment.title }}</span>
		</div>
		<PartView v-for="part in turn.parts"
			:key="part.key"
			:part="part"
			:host="host" />
		<p v-if="turn.statusText" class="fdn-status" role="status">
			<span v-if="turn.isRunning" class="fdn-spinner" />
			{{ turn.statusText }}
		</p>
		<div v-if="!turn.isRunning" class="fdn-turn-actions">
			<button v-if="turn.answerText"
				type="button"
				class="fdn-icon-button fdn-small"
				:aria-label="t('fadenstack_agent', 'Copy answer')"
				:title="t('fadenstack_agent', 'Copy answer')"
				@click="turn.copy()">
				<ChatIcon name="copy" :size="14" />
			</button>
			<button type="button"
				class="fdn-icon-button fdn-small"
				:aria-label="t('fadenstack_agent', 'Edit message')"
				:title="t('fadenstack_agent', 'Edit message')"
				@click="turn.edit()">
				<ChatIcon name="edit" :size="14" />
			</button>
			<span class="fdn-muted">{{ turn.durationText }}</span>
		</div>
		<p v-if="turn.errorMessage" role="alert">
			{{ turn.errorMessage }}
		</p>
	</article>
</template>
