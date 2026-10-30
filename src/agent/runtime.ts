import { markRaw, shallowReactive } from 'vue'
import { getLanguage } from '@nextcloud/l10n'
import { NextcloudAgent } from './createAgent.ts'
import { NextcloudFiles, errorMessage } from '../integrations/nextcloud/files.ts'
import type { Identity } from '../integrations/nextcloud/types.ts'
import { NativeWorkspace } from '../workspace/nativeWorkspace.ts'
import { WorkspaceCoordinator } from '../workspace/coordinator.ts'
import { refreshFiles } from '../workspace/filesRefresh.ts'

/** One runtime per authenticated page, retained when Files unmounts a tab. */
export function createRuntime(config: Identity) {
	const native = new NativeWorkspace()
	const workspace = new WorkspaceCoordinator({ guard: native, prepareProvider: app => native.prepare(app), refresh: (result, signal) => result.provider ? native.refresh(result, signal) : refreshFiles(result, signal) })
	const files = new NextcloudFiles(config)
	const state = shallowReactive({ host: undefined as NextcloudAgent | undefined, signedIn: false, checking: false, error: '' })
	const ensure = () => (state.host ??= markRaw(new NextcloudAgent({ ...config, locale: getLanguage().replaceAll('_', '-') }, files, undefined, workspace)))
	const clear = (reason = '') => {
		const host = state.host
		state.host = undefined
		state.signedIn = false
		state.error = reason
		void host?.dispose()
	}
	const verify = async () => {
		if (!state.host) return
		state.checking = true
		try { await files.assertIdentity() } catch (error) { clear(errorMessage(error)) } finally { state.checking = false }
	}
	const timer = setInterval(() => { void verify() }, 15000)
	const onVisibility = () => { if (!document.hidden) void verify() }
	document.addEventListener('visibilitychange', onVisibility)
	window.addEventListener('pagehide', () => { clear(); workspace.dispose(); clearInterval(timer); document.removeEventListener('visibilitychange', onVisibility) }, { once: true })
	const connected = () => { state.signedIn = true; state.error = '' }
	return { config, files, workspace, state, ensure, clear, verify, connected }
}
export type Runtime = ReturnType<typeof createRuntime>
