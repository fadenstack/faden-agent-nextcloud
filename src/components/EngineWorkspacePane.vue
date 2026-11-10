<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import { Icon } from '@fadenstack/ui-vue'
import type { EngineSurface, WorkflowSummary } from '../workspace/engineSurface.ts'

const props = defineProps<{ surface: EngineSurface }>()

const workflows = ref<readonly WorkflowSummary[]>([])
const loadingWorkflows = ref(false)
const selectedReference = ref('')
const showCustomDraftInput = ref(false)
const customDraftPath = ref('')
const selectError = ref('')

async function fetchWorkflows() {
	if (loadingWorkflows.value) return
	loadingWorkflows.value = true
	selectError.value = ''
	try {
		workflows.value = await props.surface.loadWorkflows()
	} catch (error) {
		selectError.value = error instanceof Error ? error.message : t('fadenstack_agent', 'Could not load workflows.')
	} finally {
		loadingWorkflows.value = false
	}
}

async function onSelectWorkflow(event?: Event) {
	const selectEl = event?.target as HTMLSelectElement | undefined
	if (selectEl) selectedReference.value = selectEl.value
	const refValue = selectedReference.value
	if (!refValue) {
		props.surface.setTarget(undefined)
		showCustomDraftInput.value = false
		return
	}
	if (refValue === '__custom_draft__') {
		showCustomDraftInput.value = true
		return
	}
	showCustomDraftInput.value = false
	const found = workflows.value.find(w => w.reference === refValue)
	try {
		await props.surface.selectTarget(refValue, 'saved', found?.name)
	} catch (error) {
		selectError.value = error instanceof Error ? error.message : t('fadenstack_agent', 'Failed to select target.')
	}
}

async function selectCustomDraft() {
	const path = customDraftPath.value.trim()
	if (!path) return
	selectError.value = ''
	try {
		await props.surface.selectTarget(path, 'own-draft', path)
		showCustomDraftInput.value = false
		customDraftPath.value = ''
	} catch (error) {
		selectError.value = error instanceof Error ? error.message : t('fadenstack_agent', 'Could not select private draft.')
	}
}

function clearTarget() {
	selectedReference.value = ''
	showCustomDraftInput.value = false
	props.surface.setTarget(undefined)
}

function toggleRefresh(event: Event) {
	const input = event.target as HTMLInputElement
	props.surface.authorizeRefresh(input.checked)
}

function dismissResult() {
	props.surface.dismissResult()
}

function onFrameLoad(event: Event) {
	props.surface.loaded(Number((event.currentTarget as HTMLIFrameElement).dataset.generation))
}

watch(() => props.surface.state.target, target => {
	if (!target) {
		selectedReference.value = ''
	} else if (target.sourceKind === 'own-draft') {
		selectedReference.value = '__custom_draft__'
	} else {
		selectedReference.value = target.reference
	}
})

onMounted(() => {
	void fetchWorkflows()
})
</script>

<template>
	<section class="fadenstack-engine-workspace" :aria-label="surface.descriptor.label">
		<div class="fadenstack-engine-workspace__toolbar" role="toolbar" :aria-label="t('fadenstack_agent', 'Workspace toolbar')">
			<strong><Icon name="page" :size="16" /> {{ surface.descriptor.label }}</strong>
			<span class="fadenstack-engine-badge fadenstack-engine-account"
				:title="surface.descriptor.apiPrincipal ? t('fadenstack_agent', 'API credentials verified for execution') : t('fadenstack_agent', 'API execution account unverifiable')">
				{{ surface.descriptor.apiPrincipal ? t('fadenstack_agent', 'API: {name}', { name: surface.descriptor.apiPrincipal }) : t('fadenstack_agent', 'API: unverifiable') }}
			</span>
			<span class="fadenstack-engine-badge fadenstack-engine-editor-status"
				:title="t('fadenstack_agent', 'Native editor state cannot be observed across origins')">
				{{ t('fadenstack_agent', 'Editor: unobservable') }}
			</span>
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
			<label class="fadenstack-engine-refresh-toggle"
				:title="t('fadenstack_agent', 'Pause first. This permits one child replacement after a verified draft save and may discard unsaved native edits. Editor state stays unknown.')">
				<input type="checkbox"
					:checked="surface.state.refreshAuthorized"
					:disabled="surface.state.busy || !surface.state.paused"
					@change="toggleRefresh">
				<span>{{ t('fadenstack_agent', 'I saved or discarded native edits — allow one automatic child replacement') }}</span>
			</label>
		</div>
		<div v-if="surface.state.pendingNavigation"
			role="alertdialog"
			:aria-label="t('fadenstack_agent', 'Replace native editor?')"
			class="fadenstack-engine-results-banner">
			<p>{{ t('fadenstack_agent', 'This replaces the native editor and can discard unsaved edits. Save or discard your native changes first. The explicit agent target stays unchanged.') }}</p>
			<button type="button" :disabled="surface.state.busy" @click="surface.confirmNavigation()">
				{{ t('fadenstack_agent', 'I saved or discarded my edits — continue') }}
			</button>
			<button type="button" @click="surface.cancelNavigation()">
				{{ t('fadenstack_agent', 'Cancel — keep editor') }}
			</button>
		</div>

		<!-- Target Workflow Selection Bar -->
		<div class="fadenstack-engine-workspace__target-bar" role="region" :aria-label="t('fadenstack_agent', 'Workflow target selection')">
			<span class="fadenstack-engine-target-label">{{ t('fadenstack_agent', 'Workflow target:') }}</span>

			<div v-if="surface.state.target" class="fadenstack-engine-target-selected">
				<Icon name="page" :size="14" />
				<strong class="fadenstack-engine-target-name">{{ surface.state.target.name }}</strong>
				<code class="fadenstack-engine-target-ref">{{ surface.state.target.reference }}</code>
				<span class="fadenstack-engine-badge fadenstack-engine-kind">
					{{ surface.state.target.sourceKind === 'own-draft' ? t('fadenstack_agent', 'Private draft') : t('fadenstack_agent', 'Saved workflow') }}
				</span>
				<button v-if="surface.descriptor.provider === 'windmill'"
					type="button"
					class="fadenstack-btn-sm"
					:disabled="surface.state.busy"
					:title="t('fadenstack_agent', 'Navigate native editor to this workflow')"
					@click="surface.navigateToTarget(surface.state.target)">
					{{ t('fadenstack_agent', 'Navigate editor') }}
				</button>
				<a v-if="surface.descriptor.provider === 'n8n'"
					:href="surface.targetUrl(surface.state.target.reference)"
					target="_blank"
					rel="noopener noreferrer"
					class="fadenstack-link-sm"
					:title="t('fadenstack_agent', 'Open target workflow in a new tab')">
					{{ t('fadenstack_agent', 'Open in new tab') }}
				</a>
				<button type="button"
					class="fadenstack-btn-sm fadenstack-btn-clear"
					:disabled="surface.state.busy"
					:aria-label="t('fadenstack_agent', 'Clear target selection')"
					@click="clearTarget">
					{{ t('fadenstack_agent', 'Clear') }}
				</button>
			</div>

			<div v-else class="fadenstack-engine-target-selector">
				<select v-model="selectedReference"
					:disabled="surface.state.busy || loadingWorkflows"
					aria-label="Select workflow target"
					@change="onSelectWorkflow">
					<option value="">
						{{ loadingWorkflows ? t('fadenstack_agent', 'Loading workflows…') : t('fadenstack_agent', '— Select an authorized workflow target —') }}
					</option>
					<option v-for="w in workflows" :key="w.reference" :value="w.reference">
						{{ w.name }} ({{ w.reference }})
					</option>
					<option v-if="surface.descriptor.provider === 'windmill'" value="__custom_draft__">
						{{ t('fadenstack_agent', '— Specify known private draft… —') }}
					</option>
				</select>
				<button v-if="!workflows.length && !loadingWorkflows"
					type="button"
					class="fadenstack-btn-sm"
					:disabled="surface.state.busy"
					@click="fetchWorkflows">
					{{ t('fadenstack_agent', 'Load workflows') }}
				</button>
			</div>

			<div v-if="showCustomDraftInput && !surface.state.target" class="fadenstack-engine-custom-draft">
				<input v-model="customDraftPath"
					type="text"
					placeholder="u/username/draft_path"
					:disabled="surface.state.busy"
					:aria-label="t('fadenstack_agent', 'Private draft path')"
					@keydown.enter.prevent="selectCustomDraft">
				<button type="button"
					class="fadenstack-btn-sm"
					:disabled="surface.state.busy || !customDraftPath.trim()"
					@click="selectCustomDraft">
					{{ t('fadenstack_agent', 'Select private draft') }}
				</button>
				<button type="button"
					class="fadenstack-btn-sm"
					:disabled="surface.state.busy"
					@click="showCustomDraftInput = false">
					{{ t('fadenstack_agent', 'Cancel') }}
				</button>
			</div>

			<span v-if="selectError" class="fadenstack-engine-error" role="alert">{{ selectError }}</span>
		</div>

		<!-- Committed Results Banner with Original vs Copy Navigation -->
		<div v-if="surface.state.lastResult" class="fadenstack-engine-results-banner" role="status">
			<div class="fadenstack-engine-results-content">
				<strong>{{ t('fadenstack_agent', 'Operation complete: {name}', { name: surface.state.lastResult.createdName }) }}</strong>
				<div class="fadenstack-engine-results-details">
					<span v-if="surface.state.lastResult.sourceReference" class="fadenstack-engine-results-source">
						{{ t('fadenstack_agent', 'Source: {ref}', { ref: surface.state.lastResult.sourceReference }) }}
					</span>
					<span v-if="surface.state.lastResult.isCopy" class="fadenstack-engine-results-copy">
						{{ t('fadenstack_agent', 'New copy: {ref}', { ref: surface.state.lastResult.createdReference }) }}
					</span>
					<span v-else class="fadenstack-engine-results-target">
						{{ t('fadenstack_agent', 'Target: {ref}', { ref: surface.state.lastResult.createdReference }) }}
					</span>
				</div>
			</div>
			<div class="fadenstack-engine-results-actions">
				<template v-if="surface.descriptor.provider === 'windmill'">
					<button type="button"
						class="fadenstack-btn-sm"
						:disabled="surface.state.busy"
						@click="surface.navigateChild(surface.targetUrl(surface.state.lastResult!.createdReference))">
						{{ surface.state.lastResult.isCopy ? t('fadenstack_agent', 'Navigate editor to new copy') : t('fadenstack_agent', 'Navigate editor to new draft') }}
					</button>
					<button v-if="surface.state.lastResult.sourceReference"
						type="button"
						class="fadenstack-btn-sm"
						:disabled="surface.state.busy"
						@click="surface.navigateChild(surface.targetUrl(surface.state.lastResult!.sourceReference))">
						{{ t('fadenstack_agent', 'Navigate editor to source') }}
					</button>
				</template>
				<template v-else>
					<a :href="surface.targetUrl(surface.state.lastResult.createdReference)"
						target="_blank"
						rel="noopener noreferrer"
						class="fadenstack-link-sm">
						{{ surface.state.lastResult.isCopy ? t('fadenstack_agent', 'Open new copy in new tab') : t('fadenstack_agent', 'Open new draft in new tab') }}
					</a>
					<a v-if="surface.state.lastResult.sourceReference"
						:href="surface.targetUrl(surface.state.lastResult.sourceReference)"
						target="_blank"
						rel="noopener noreferrer"
						class="fadenstack-link-sm">
						{{ t('fadenstack_agent', 'Open source in new tab') }}
					</a>
				</template>
				<button type="button"
					class="fadenstack-btn-sm fadenstack-btn-clear"
					:aria-label="t('fadenstack_agent', 'Dismiss result banner')"
					@click="dismissResult">
					<Icon name="close" :size="14" />
				</button>
			</div>
		</div>

		<p class="fadenstack-engine-workspace__notice">
			{{ t('fadenstack_agent', 'Sign in to the native editor separately if asked. Your API connection does not sign in this view. Editor state is unknown; pause preserves unsaved edits. Changes are never refreshed automatically unless authorized. If framing is blocked, open the native editor in a new tab.') }}
		</p>
		<p v-if="surface.state.loadStatus === 'loaded-unverified'" class="fadenstack-engine-workspace__notice" role="status">
			{{ t('fadenstack_agent', 'Native child loaded. Check its sign-in and workflow: cross-origin loading does not verify the displayed result.') }}
		</p>
		<p v-else-if="surface.state.loadStatus === 'failed' || surface.state.loadStatus === 'stopped'" class="fadenstack-engine-workspace__notice" role="status">
			{{ t('fadenstack_agent', 'Native display could not be confirmed. Inspect the recorded action; do not repeat the save.') }}
		</p>
		<div class="fadenstack-engine-workspace__view">
			<iframe :key="surface.state.generation"
				:data-generation="surface.state.generation"
				:src="surface.state.currentUrl"
				:title="t('fadenstack_agent', 'Native workflow editor: {name}', { name: surface.descriptor.label })"
				:inert.attr="surface.state.paused || surface.state.busy ? true : undefined"
				referrerpolicy="no-referrer"
				@load="onFrameLoad" />
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

.fadenstack-engine-workspace__toolbar { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 14px; padding: 10px 12px; border-bottom: 1px solid var(--color-border); }

.fadenstack-engine-workspace__toolbar strong { display: inline-flex; align-items: center; gap: 8px; }

.fadenstack-engine-workspace__toolbar button { min-height: 32px; padding: 4px 8px; border: 1px solid var(--color-border); border-radius: var(--border-radius-element, 8px); color: inherit; background: var(--color-main-background); font: inherit; cursor: pointer; }

.fadenstack-engine-workspace__toolbar button:disabled { opacity: .5; cursor: default; }

.fadenstack-engine-workspace__toolbar button:focus-visible,
.fadenstack-engine-workspace a:focus-visible,
.fadenstack-engine-workspace select:focus-visible,
.fadenstack-engine-workspace input:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }

.fadenstack-engine-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: var(--border-radius-pill, 12px); font-size: var(--font-size-small, 13px); background: var(--color-background-hover); color: var(--color-text-maxcontrast); border: 1px solid var(--color-border); }

.fadenstack-engine-account { font-weight: 500; color: var(--color-main-text); }

.fadenstack-engine-editor-status { font-style: italic; }

.fadenstack-engine-refresh-toggle { display: inline-flex; align-items: center; gap: 6px; font-size: var(--font-size-small, 13px); cursor: pointer; user-select: none; }

.fadenstack-engine-refresh-toggle input { cursor: pointer; }

/* Target selection bar */
.fadenstack-engine-workspace__target-bar { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 12px; padding: 8px 12px; background: color-mix(in srgb, var(--color-main-background) 95%, var(--color-primary-element)); border-bottom: 1px solid var(--color-border); font-size: var(--font-size-small, 14px); }

.fadenstack-engine-target-label { font-weight: 600; flex-shrink: 0; }

.fadenstack-engine-target-selected { display: inline-flex; align-items: center; flex-wrap: wrap; gap: 8px; min-width: 0; }

.fadenstack-engine-target-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 200px; }

.fadenstack-engine-target-ref { padding: 1px 4px; background: var(--color-background-hover); border-radius: 4px; font-family: monospace; font-size: 12px; }

.fadenstack-engine-kind { background: var(--color-primary-light); color: var(--color-primary-text, var(--color-main-text)); font-size: 11px; }

.fadenstack-engine-target-selector { display: inline-flex; align-items: center; gap: 8px; flex: 1; min-width: 200px; max-width: 480px; }

.fadenstack-engine-target-selector select { width: 100%; min-height: 30px; padding: 3px 6px; border: 1px solid var(--color-border); border-radius: var(--border-radius-element, 6px); background: var(--color-main-background); color: var(--color-main-text); font: inherit; font-size: 13px; }

.fadenstack-engine-custom-draft { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }

.fadenstack-engine-custom-draft input { min-height: 28px; padding: 2px 6px; border: 1px solid var(--color-border); border-radius: var(--border-radius-element, 6px); background: var(--color-main-background); color: var(--color-main-text); font: inherit; font-size: 13px; min-width: 180px; }

.fadenstack-btn-sm { display: inline-flex; align-items: center; gap: 4px; min-height: 28px; padding: 2px 8px; border: 1px solid var(--color-border); border-radius: var(--border-radius-element, 6px); background: var(--color-main-background); color: inherit; font: inherit; font-size: 12px; cursor: pointer; }

.fadenstack-btn-sm:disabled { opacity: .5; cursor: default; }

.fadenstack-link-sm { display: inline-flex; align-items: center; gap: 4px; min-height: 28px; padding: 2px 8px; border: 1px solid var(--color-border); border-radius: var(--border-radius-element, 6px); background: var(--color-main-background); color: var(--color-primary-element); text-decoration: none; font: inherit; font-size: 12px; }

.fadenstack-btn-clear { background: transparent; }

.fadenstack-engine-error { color: var(--color-error); font-size: 12px; }

/* Committed results banner */
.fadenstack-engine-results-banner { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px 12px; padding: 8px 12px; background: color-mix(in srgb, var(--color-success) 12%, var(--color-main-background)); border: 1px solid var(--color-success); border-radius: var(--border-radius-element, 6px); margin: 6px 12px; font-size: 13px; }

.fadenstack-engine-results-content { display: flex; flex-direction: column; gap: 2px; }

.fadenstack-engine-results-details { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 16px; font-size: 12px; color: var(--color-text-maxcontrast); }

.fadenstack-engine-results-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }

.fadenstack-engine-workspace__notice { margin: 0; padding: 8px 12px 10px; font-size: var(--font-size-small, 13px); color: var(--color-text-maxcontrast); }

.fadenstack-engine-workspace__view { position: relative; flex: 1; min-height: 280px; }

.fadenstack-engine-workspace iframe { width: 100%; height: 100%; border: 0; }

.fadenstack-engine-workspace__cover { position: absolute; inset: 0; display: grid; place-content: start center; padding: 20px; background: color-mix(in srgb, var(--color-main-background) 80%, transparent); border: 2px solid var(--color-primary-element); }

@media (width < 800px) {
	.fadenstack-engine-workspace__toolbar { gap: 6px 10px; }
	.fadenstack-engine-target-selector { min-width: 100%; max-width: 100%; }
}
</style>
