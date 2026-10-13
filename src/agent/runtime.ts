import { markRaw, shallowReactive } from 'vue'
import { NextcloudAgent } from './createAgent.ts'
import { NextcloudFiles } from '../integrations/nextcloud/files.ts'
import type { Identity } from '../integrations/nextcloud/types.ts'

/** One runtime per authenticated page, retained when Files unmounts a tab. */
export function createRuntime(config: Identity) {
	const files = new NextcloudFiles(config)
	const state = shallowReactive({ host: undefined as NextcloudAgent | undefined, signedIn: false, checking: false })
	const ensure = () => (state.host ??= markRaw(new NextcloudAgent(config, files)))
	const clear = () => {
		const host = state.host
		state.host = undefined
		state.signedIn = false
		void host?.dispose()
	}
	const verify = async () => {
		if (!state.host) return
		state.checking = true
		try { await files.assertIdentity() } catch { clear() } finally { state.checking = false }
	}
	const timer = setInterval(() => { void verify() }, 15000)
	const onVisibility = () => { if (!document.hidden) void verify() }
	document.addEventListener('visibilitychange', onVisibility)
	window.addEventListener('pagehide', () => { clear(); clearInterval(timer); document.removeEventListener('visibilitychange', onVisibility) }, { once: true })
	const connected = () => { state.signedIn = true }
	return { config, files, state, ensure, clear, verify, connected }
}
export type Runtime = ReturnType<typeof createRuntime>
