<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import { displayDavDate } from '../providers/davDate.ts'

const props = defineProps<{ before: unknown; after: unknown; nativeContent?: unknown; deleted?: boolean }>()
const labels: Record<string, string> = {
	SUMMARY: t('fadenstack_agent', 'Title'),
	DTSTART: t('fadenstack_agent', 'Starts'),
	DTEND: t('fadenstack_agent', 'Ends'),
	DUE: t('fadenstack_agent', 'Due'),
	LOCATION: t('fadenstack_agent', 'Location'),
	DESCRIPTION: t('fadenstack_agent', 'Description'),
	STATUS: t('fadenstack_agent', 'Status'),
	'PERCENT-COMPLETE': t('fadenstack_agent', 'Completion'),
	PRIORITY: t('fadenstack_agent', 'Priority'),
	FN: t('fadenstack_agent', 'Name'),
	EMAIL: t('fadenstack_agent', 'Email'),
	TEL: t('fadenstack_agent', 'Phone'),
	NOTE: t('fadenstack_agent', 'Note'),
	CATEGORIES: t('fadenstack_agent', 'Categories'),
}
function record(value: unknown): Record<string, unknown> { return value && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {} }
function text(key: string, value: unknown): string {
	if (value === undefined || value === null || value === '') return '—'
	const result = String(value)
	if (['DTSTART', 'DTEND', 'DUE'].includes(key)) {
		const date = displayDavDate(result)
		return date.text + (date.allDay ? ' · ' + t('fadenstack_agent', 'All day') : '')
	}
	return result
}
const fields = computed(() => {
	const before = record(props.before); const after = props.deleted ? before : record(props.after)
	return Object.entries(labels).filter(([key]) => before[key] !== undefined || after[key] !== undefined).map(([key, label]) => ({
		key, label, before: text(key, before[key]), after: text(key, after[key]), changed: !props.deleted && props.before !== null && before[key] !== after[key],
	}))
})
</script>

<template>
	<section>
		<dl class="fadenstack-native-fields">
			<div v-for="field in fields" :key="field.key" class="fadenstack-native-fields__row">
				<dt>{{ field.label }}</dt>
				<dd><span v-if="field.changed" class="fadenstack-native-fields__previous">{{ field.before }} → </span>{{ field.after }}</dd>
			</div>
		</dl>
		<details v-if="typeof nativeContent === 'string' && nativeContent">
			<summary>{{ t('fadenstack_agent', 'Exact native content') }}</summary>
			<pre class="fdn-approval-args" dir="auto">{{ nativeContent }}</pre>
		</details>
	</section>
</template>

<style scoped>
.fadenstack-native-fields { display: grid; grid-template-columns: minmax(80px, auto) minmax(0, 1fr); gap: 6px 12px; margin: 8px 0; font: inherit; }

.fadenstack-native-fields dt { color: var(--color-text-maxcontrast); }

.fadenstack-native-fields__row { display: contents; }

.fadenstack-native-fields dd { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }

.fadenstack-native-fields__previous { color: var(--color-text-maxcontrast); }
</style>
