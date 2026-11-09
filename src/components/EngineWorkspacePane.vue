<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { Icon } from '@fadenstack/ui-vue'
import type { EngineSurface } from '../workspace/engineSurface.ts'
defineProps<{ surface: EngineSurface }>()
</script>

<template>
	<section class="fadenstack-engine-workspace" :aria-label="surface.descriptor.label">
		<div class="fadenstack-engine-workspace__toolbar">
			<strong><Icon name="page" :size="16" /> {{ surface.descriptor.label }}</strong>
			<a :href="surface.descriptor.url" target="_blank" rel="noopener noreferrer">{{ t('fadenstack_agent', 'Open native editor in a new tab') }}</a>
			<button v-if="!surface.state.paused"
				type="button"
				:disabled="surface.state.busy"
				@click="surface.pause()">
				{{ t('fadenstack_agent', 'Pause editor for agent changes') }}
			</button>
			<button v-else
				type="button"
				:disabled="surface.state.busy"
				@click="surface.resume()">
				{{ t('fadenstack_agent', 'Resume native editor') }}
			</button>
			<button type="button" :disabled="surface.state.busy" @click="surface.reloadAcknowledged()">
				{{ t('fadenstack_agent', 'I saved or cancelled my edits — reload editor') }}
			</button>
		</div>
		<p class="fadenstack-engine-workspace__notice">
			{{ t('fadenstack_agent', 'Sign in to the native editor separately if asked. Your API connection does not sign in this view. Editor state is unknown; pause preserves unsaved edits. Changes are never refreshed automatically. If framing is blocked, open the native editor in a new tab.') }}
		</p>
		<div class="fadenstack-engine-workspace__view">
			<iframe :key="surface.state.generation"
				:src="surface.descriptor.url"
				:title="t('fadenstack_agent', 'Native workflow editor: {name}', { name: surface.descriptor.label })"
				:inert.attr="surface.state.paused || surface.state.busy ? true : undefined"
				referrerpolicy="no-referrer"
				@load="surface.loaded()" />
			<div v-if="surface.state.paused || surface.state.busy" class="fadenstack-engine-workspace__cover" role="status">
				{{ surface.state.busy ? t('fadenstack_agent', 'Assistant is working. Use the shared Stop control to stop further agent work.') : t('fadenstack_agent', 'Native editor paused. Unsaved content is retained. Resume it when you are ready.') }}
			</div>
		</div>
	</section>
</template>

<style scoped>
:global(.fadenstack-engine-page) { display: flex; flex-direction: column; }

:global(#fadenstack-engine-surface) { display: flex; flex: 1; min-height: 0; }

.fadenstack-engine-workspace { display: flex; flex: 1; flex-direction: column; min-width: 0; min-height: 0; font: inherit; }

.fadenstack-engine-workspace__toolbar { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 16px; padding: 12px; }

.fadenstack-engine-workspace__toolbar strong { display: inline-flex; align-items: center; gap: 8px; }

.fadenstack-engine-workspace__toolbar button { min-height: 34px; padding: 4px 8px; border: 1px solid var(--color-border); border-radius: var(--border-radius-element, 8px); color: inherit; background: var(--color-main-background); font: inherit; cursor: pointer; }

.fadenstack-engine-workspace__toolbar button:disabled { opacity: .5; cursor: default; }

.fadenstack-engine-workspace__toolbar button:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }

.fadenstack-engine-workspace__notice { margin: 0; padding: 0 12px 12px; font: inherit; }

.fadenstack-engine-workspace__view { position: relative; flex: 1; min-height: 280px; }

.fadenstack-engine-workspace iframe { width: 100%; height: 100%; border: 0; }

.fadenstack-engine-workspace__cover { position: absolute; inset: 0; display: grid; place-content: start center; padding: 20px; background: color-mix(in srgb, var(--color-main-background) 80%, transparent); border: 2px solid var(--color-primary-element); }
</style>
