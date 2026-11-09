<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { AgentAvatar } from '@fadenstack/ui-vue'
import type { NativeProvidersApi } from '../providers/client.ts'
import type { EngineConnectionSummary, EngineWebhookSummary, EngineWorkspaceLink, EngineOAuthSummary } from '../providers/engineConnections.ts'

const props = defineProps<{ api: NativeProvidersApi }>()
const emit = defineEmits<{ close: [] }>()
const open = ref(true)
const connections = ref<readonly EngineConnectionSummary[]>([])
const workspaces = ref<readonly EngineWorkspaceLink[]>([])
const oauthTargets = ref<readonly EngineOAuthSummary[]>([])
const ownOAuth = computed(() => oauthTargets.value.filter(row => row.connectionId === selectedId.value))
const authorization = ref<{ targetId: string; href: string } | null>(null)
const workspace = computed(() => workspaces.value.find(item => item.connectionId === selectedId.value))
const selectedId = ref('')
const targets = ref<readonly EngineWebhookSummary[]>([])
const selectedTarget = ref('')
const webhookSecret = ref('')
const ownTargets = computed(() => targets.value.filter(target => target.connectionId === selectedId.value))
const hook = computed(() => ownTargets.value.find(target => target.id === selectedTarget.value))
const token = ref('')
const busy = ref(false)
const error = ref('')
const notice = ref('')
const selectorId = useId()
const selected = computed(() => connections.value.find(item => item.id === selectedId.value))
const abort = new AbortController()
let workspaceEpoch = 0
let oauthEpoch = 0
watch(open, value => { if (!value) emit('close') })
watch(selectedId, () => { token.value = ''; webhookSecret.value = ''; authorization.value = null; selectedTarget.value = ownTargets.value[0]?.id ?? ''; notice.value = ''; error.value = '' })
watch(selectedTarget, () => { webhookSecret.value = '' })
onBeforeUnmount(() => { token.value = ''; webhookSecret.value = ''; abort.abort() })

/** Optional navigation must never delay or prevent native credential revocation. */
async function loadWorkspaces() {
	const epoch = ++workspaceEpoch
	workspaces.value = []
	try {
		const values = await props.api.engineWorkspaces?.(abort.signal) ?? []
		if (!abort.signal.aborted && epoch === workspaceEpoch) workspaces.value = values
	} catch { /* API credentials remain manageable when editor navigation is unavailable. */ }
}
async function loadOAuth() {
	const epoch = ++oauthEpoch
	oauthTargets.value = []
	try {
		const rows = await props.api.engineOAuth?.(abort.signal) ?? []
		if (!abort.signal.aborted && epoch === oauthEpoch) {
			oauthTargets.value = rows
			if (authorization.value && !rows.some(row => row.id === authorization.value!.targetId && row.state === 'pending')) authorization.value = null
		}
	} catch { /* Optional OAuth setup never blocks local credential removal. */ }
}

async function load() {
	busy.value = true
	error.value = ''
	try {
		connections.value = await props.api.engineConnections!(abort.signal)
		targets.value = await props.api.engineWebhooks?.(abort.signal) ?? []
		if (!connections.value.some(item => item.id === selectedId.value)) selectedId.value = connections.value[0]?.id ?? ''
		if (!ownTargets.value.some(target => target.id === selectedTarget.value)) selectedTarget.value = ownTargets.value[0]?.id ?? ''
	} catch { error.value = t('fadenstack_agent', 'Workflow connections are unavailable. Reload to check their current state.') } finally { busy.value = false }
	void loadWorkspaces()
	void loadOAuth()
}
onMounted(load)

async function change(disconnect = false) {
	const current = selected.value
	if (!current || busy.value) return
	const secret = token.value
	token.value = ''
	busy.value = true
	error.value = ''
	notice.value = ''
	try {
		const result = disconnect
			? await props.api.engineDisconnect!(current.id, current.revision, abort.signal)
			: await props.api.engineConnect!(current.id, current.revision, secret, abort.signal)
		connections.value = connections.value.map(item => item.id === result.id ? result : item)
		targets.value = await props.api.engineWebhooks?.(abort.signal) ?? []
		authorization.value = null
		notice.value = disconnect ? t('fadenstack_agent', 'Local access removed. New agent requests cannot use this credential. Revoke previously issued OAuth grants in n8n to invalidate them remotely.') : t('fadenstack_agent', 'API access verified and token saved in your Nextcloud account.')
	} catch { error.value = t('fadenstack_agent', 'The connection could not be confirmed. Reload before changing it again.') } finally { busy.value = false }
	void loadWorkspaces()
	void loadOAuth()
}
async function changeOAuth(target: EngineOAuthSummary, operation: 'start' | 'refresh' | 'remove') {
	const current = selected.value
	if (!current || busy.value) return
	busy.value = true; error.value = ''; notice.value = ''; authorization.value = null
	try {
		if (operation === 'start') {
			authorization.value = { targetId: target.id, href: await props.api.engineOAuthStart!(target, current.revision, abort.signal) }
			notice.value = t('fadenstack_agent', 'Continue to n8n in a new tab. After consent, return here and reload connections. No workflow is run by connecting.')
		} else {
			await (operation === 'refresh' ? props.api.engineOAuthRefresh! : props.api.engineOAuthRemove!)(target.id, current.id, current.revision, abort.signal)
			notice.value = operation === 'refresh' ? t('fadenstack_agent', 'OAuth grant refreshed. Earlier execution previews need new approval.') : t('fadenstack_agent', 'Local OAuth access and pending consent removed. Revoke the grant in n8n to invalidate tokens already issued.')
		}
		connections.value = await props.api.engineConnections!(abort.signal)
	} catch { error.value = t('fadenstack_agent', 'OAuth is unconfirmed. Reload connections. An uncertain exchange or refresh is not retried; cancel local access and reconnect if required.') } finally { busy.value = false }
	void loadOAuth()
}
async function changeWebhook(remove = false) {
	const current = selected.value
	const target = hook.value
	if (!current || !target || busy.value) return
	const secret = webhookSecret.value
	webhookSecret.value = ''; busy.value = true; error.value = ''; notice.value = ''
	try {
		const result = await props.api.engineWebhookChange!(target.id, current.id, current.revision, remove ? null : secret, abort.signal)
		connections.value = connections.value.map(item => item.id === result.id ? result : item)
		targets.value = await props.api.engineWebhooks!(abort.signal)
		notice.value = remove ? t('fadenstack_agent', 'Webhook key removed. New agent dispatch cannot use it.') : t('fadenstack_agent', 'Webhook key stored encrypted. Its validity is checked by n8n only during an approved dispatch; no test execution was sent.')
	} catch { error.value = t('fadenstack_agent', 'The webhook key change could not be confirmed. Reload before changing it again.') } finally { busy.value = false }
}
</script>

<template>
	<NcDialog :open="open" :name="t('fadenstack_agent', 'Workflow connections')" @update:open="open = $event">
		<div class="fadenstack-engine-connections" :aria-busy="busy">
			<div class="fadenstack-engine-connections__identity">
				<span class="fadenstack-engine-connections__avatar"><AgentAvatar :size="32" /></span>
				<p>{{ t('fadenstack_agent', 'Connect your own workflow account. Tokens stay encrypted in Nextcloud and are not sent to Fadenstack or the AI model.') }}</p>
			</div>
			<p v-if="error" role="alert">
				{{ error }}
			</p>
			<p v-if="notice" role="status">
				{{ notice }}
			</p>
			<p v-if="!connections.length && !busy && !error">
				{{ t('fadenstack_agent', 'Your administrator has not configured a workflow engine.') }}
			</p>
			<template v-if="connections.length">
				<label :for="selectorId">{{ t('fadenstack_agent', 'Connection') }}</label>
				<select :id="selectorId" v-model="selectedId" :disabled="busy">
					<option v-for="item in connections" :key="item.id" :value="item.id">
						{{ item.label }}
					</option>
				</select>
				<template v-if="selected">
					<p class="fadenstack-engine-connections__endpoint">
						{{ selected.baseUrl }}
						<template v-if="selected.workspace">
							· {{ selected.workspace }}
						</template>
					</p>
					<p>{{ selected.connected ? t('fadenstack_agent', 'Connected') : t('fadenstack_agent', 'Not connected') }}</p>
					<p>{{ selected.keyAccess }}</p>
					<a v-if="selected.connected && workspace"
						:href="workspace.href"
						target="_blank"
						rel="noopener noreferrer">
						{{ t('fadenstack_agent', 'Open native workflow workspace with chat') }}
					</a>
					<form v-if="selected.setupAllowed" @submit.prevent="change()">
						<NcTextField v-model="token"
							type="password"
							autocomplete="new-password"
							:disabled="busy"
							:label="t('fadenstack_agent', 'Your engine API token')" />
						<p>{{ t('fadenstack_agent', 'Connecting does not sign in the native editor or enable agent tools. This local connection expires after 30 days; the engine token may expire sooner.') }}</p>
						<NcButton type="submit" variant="primary" :disabled="busy || !token">
							{{ selected.connected ? t('fadenstack_agent', 'Replace token') : t('fadenstack_agent', 'Connect') }}
						</NcButton>
					</form>
					<NcButton :disabled="busy" @click="change(true)">
						{{ t('fadenstack_agent', 'Disconnect') }}
					</NcButton>
					<section v-for="target in selected.connected ? ownOAuth : []" :key="target.id" class="fadenstack-engine-oauth">
						<p><strong>{{ t('fadenstack_agent', 'User-bound webhook OAuth') }}</strong> · {{ target.workflowId }}</p>
						<p>{{ target.issuer }} · {{ target.state }}</p>
						<p>{{ t('fadenstack_agent', 'OAuth authorizes this published webhook. Your API key still handles reads and drafts. n8n execution history may retain the bearer token and inputs. Native sign-in is separate; SSO is not required.') }}</p>
						<NcButton v-if="target.state === 'disconnected'" :disabled="busy" @click="changeOAuth(target, 'start')">
							{{ t('fadenstack_agent', 'Connect webhook OAuth') }}
						</NcButton>
						<a v-if="authorization?.targetId === target.id && target.state === 'pending'"
							:href="authorization.href"
							target="_blank"
							rel="noopener noreferrer">
							{{ t('fadenstack_agent', 'Continue to n8n') }}
						</a>
						<p v-if="target.state === 'pending'">
							{{ t('fadenstack_agent', 'Waiting for native consent. If you denied or closed the native tab, cancel local access before starting again. Pending consent expires after ten minutes.') }}
						</p>
						<p v-if="target.state === 'reconnect'">
							{{ t('fadenstack_agent', 'This grant is unconfirmed or its configuration changed. Remove local OAuth access, then connect again. The earlier exchange will not be repeated.') }}
						</p>
						<NcButton v-if="['connected', 'expired'].includes(target.state)" :disabled="busy" @click="changeOAuth(target, 'refresh')">
							{{ t('fadenstack_agent', 'Refresh OAuth grant') }}
						</NcButton>
						<NcButton v-if="target.state !== 'disconnected'" :disabled="busy" @click="changeOAuth(target, 'remove')">
							{{ t('fadenstack_agent', 'Remove local OAuth access') }}
						</NcButton>
					</section>
					<form v-if="selected.connected && selected.provider === 'n8n' && ownTargets.length && api.engineWebhookChange" @submit.prevent="changeWebhook()">
						<label :for="selectorId + '-webhook'">{{ t('fadenstack_agent', 'Published webhook target') }}</label>
						<select :id="selectorId + '-webhook'" v-model="selectedTarget" :disabled="busy">
							<option v-for="target in ownTargets" :key="target.id" :value="target.id">
								{{ target.id }} · {{ target.workflowId }}
							</option>
						</select>
						<p v-if="hook">
							{{ hook.secretStored ? t('fadenstack_agent', 'Webhook key stored') : t('fadenstack_agent', 'No webhook key stored') }}
						</p>
						<NcTextField v-model="webhookSecret"
							type="password"
							autocomplete="new-password"
							:disabled="busy"
							:label="t('fadenstack_agent', 'Your dedicated webhook key')" />
						<p>{{ t('fadenstack_agent', 'Use Header Auth named X-Fadenstack-Workflow-Key on your own personal workflow. n8n execution history may retain this key and request data. API token replacement removes stored webhook keys. Saving this key never sends a test execution.') }}</p>
						<NcButton type="submit" variant="primary" :disabled="busy || !webhookSecret">
							{{ t('fadenstack_agent', 'Save webhook key') }}
						</NcButton>
						<NcButton :disabled="busy || !hook?.secretStored" @click="changeWebhook(true)">
							{{ t('fadenstack_agent', 'Remove webhook key') }}
						</NcButton>
					</form>
				</template>
			</template>
		</div>
		<template #actions>
			<NcButton :disabled="busy" @click="load()">
				{{ t('fadenstack_agent', 'Reload connections') }}
			</NcButton>
			<NcButton @click="open = false">
				{{ t('fadenstack_agent', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.fadenstack-engine-connections {
	display: grid;
	gap: 12px;
	font: inherit;
	color: var(--color-main-text);
}

.fadenstack-engine-connections p {
	margin: 0;
}

.fadenstack-engine-connections__identity {
	display: flex;
	align-items: center;
	gap: 12px;
}

.fadenstack-engine-connections__endpoint {
	overflow-wrap: anywhere;
	color: var(--color-text-maxcontrast);
}

.fadenstack-engine-connections__avatar {
	display: inline-flex;
	flex: 0 0 32px;
	width: 32px;
	height: 32px;
}

.fadenstack-engine-connections form {
	display: grid;
	gap: 12px;
}

.fadenstack-engine-oauth {
	display: grid;
	gap: 12px;
	padding-block: 12px;
	border-block: 1px solid var(--color-border);
	overflow-wrap: anywhere;
}

.fadenstack-engine-connections select {
	width: 100%;
	font: inherit;
}
</style>
