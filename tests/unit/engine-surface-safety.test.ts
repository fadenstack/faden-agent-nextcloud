import { describe, expect, it, vi } from 'vitest'
import { createApp, markRaw, nextTick } from 'vue'
import { EngineSurface } from '../../src/workspace/engineSurface.ts'
import EngineWorkspacePane from '../../src/components/EngineWorkspacePane.vue'
import type { NativeProvidersApi } from '../../src/providers/client.ts'
import type { WriteResult } from '../../src/integrations/nextcloud/types.ts'

const descriptor = { connectionId: 'own-engine', provider: 'windmill' as const, label: 'Own engine', url: 'https://engine.test/?workspace=own', origin: 'https://engine.test', revision: 'a'.repeat(64) }
const result: WriteResult = { operation: 'nc_windmill_draft', file: { id: 0, name: 'New draft', path: '', mime: '', size: 0, etag: '', folder: false, permissions: 0, url: '' }, provider: { app: 'windmill', collectionId: 'own-engine', filename: 'u/me/copy' } }
const receipt = { operation: 'nc_windmill_draft', connectionId: 'own-engine', actionId: 'a'.repeat(32), generation: 0, createdReference: 'u/me/copy', createdName: 'Copy', provider: 'windmill' as const }

describe('Opaque native editor safety', () => {
	it.each([undefined, null, '', false, 'b'.repeat(64)])('refuses unbound selection and discovery (%s)', async workspaceRevision => {
		for (const operation of ['list', 'select']) {
			const surface = new EngineSurface(descriptor)
			surface.setApi({ contentRead: async () => ({ workspaceRevision, workflows: [], copySource: { reference: 'u/me/first', kind: 'saved', revision: 'a'.repeat(64) } }) } as NativeProvidersApi)
			try {
				await expect(operation === 'list' ? surface.loadWorkflows() : surface.selectTarget('u/me/first')).rejects.toThrow('workflow connection or account changed')
				expect(surface.state.target).toBeUndefined()
			} finally { surface.unbind() }
		}
	})
	it('keeps the exact child on navigation request and Cancel, replacing only after saved-edits acknowledgement', async () => {
		const surface = new EngineSurface(descriptor), root = document.createElement('div')
		document.body.append(root)
		const app = createApp(EngineWorkspacePane, { surface: markRaw(surface) }); app.mount(root)
		try {
			const frame = root.querySelector('iframe')!
			surface.navigateChild(surface.targetUrl('u/me/copy')); await nextTick()
			expect(root.querySelector('[role="alertdialog"]')).not.toBeNull()
			expect(root.querySelector('iframe')).toBe(frame)
			surface.cancelNavigation(); await nextTick(); expect(root.querySelector('iframe')).toBe(frame)
			surface.navigateChild(surface.targetUrl('u/me/copy')); surface.confirmNavigation(); await nextTick()
			expect(root.querySelector('iframe')).not.toBe(frame)
			expect(surface.snapshot().editState).toBe('unknown')
		} finally { app.unmount(); root.remove() }
	})

	it('reloads the correct child while chat is busy, waits for load, and never claims verified display', async () => {
		const surface = new EngineSurface(descriptor)
		surface.pause(); surface.authorizeRefresh(true)
		surface.bind({ isBusy: true, subscribe: () => () => {} })
		let settled = false
		const pending = surface.refresh(result).then(value => { settled = true; return value })
		expect(surface.state.currentUrl).toBe(surface.targetUrl('u/me/copy'))
		expect(surface.state.refreshAuthorized).toBe(false)
		await Promise.resolve(); expect(settled).toBe(false)
		surface.loaded(0); await Promise.resolve(); expect(settled).toBe(false)
		surface.loaded(1); expect(await pending).toBe('refresh_required')
		expect(surface.state.loadStatus).toBe('loaded-unverified')
	})

	it('does not reload without explicit consent or for a different connection', async () => {
		const surface = new EngineSurface(descriptor)
		expect(await surface.refresh(result)).toBe('refresh_required')
		surface.pause(); surface.authorizeRefresh(true)
		expect(await surface.refresh({ ...result, provider: { ...result.provider!, collectionId: 'other-engine' } })).toBe('not_visible')
		expect(surface.state.generation).toBe(0)
	})

	it('preserves truthful uncertainty after Stop or timeout and consumes consent once', async () => {
		const surface = new EngineSurface(descriptor), controller = new AbortController()
		surface.pause(); surface.authorizeRefresh(true)
		const pending = surface.refresh(result, controller.signal)
		controller.abort(); expect(await pending).toBe('refresh_required')
		expect(surface.state.loadStatus).toBe('stopped')
		const generation = surface.state.generation
		expect(await surface.refresh(result)).toBe('refresh_required')
		expect(surface.state.generation).toBe(generation)
		vi.useFakeTimers()
		try {
			surface.authorizeRefresh(true)
			const timeout = surface.refresh(result)
			await vi.advanceTimersByTimeAsync(10001)
			expect(await timeout).toBe('refresh_failed')
		} finally { vi.useRealTimers() }
	})

	it.each(['connection', 'provider', 'generation'])('ignores a result from another %s', kind => {
		const surface = new EngineSurface(descriptor)
		surface.recordResult({ ...receipt, ...(kind === 'connection' ? { connectionId: 'other' } : kind === 'provider' ? { provider: 'n8n' } : { generation: 1 }) })
		expect(surface.state.lastResult).toBeUndefined()
	})

	it.each(['new-selection', 'clear', 'load', 'disconnect', 'abort'])('invalidates pending target reads after %s', async mode => {
		const surface = new EngineSurface(descriptor), resolves = new Map<string, (value: unknown) => void>(), controller = new AbortController()
		surface.setApi({ contentRead: (_provider, args) => new Promise(resolve => resolves.set(String(args.reference), resolve)) } as NativeProvidersApi)
		const first = surface.selectTarget('u/me/first', 'saved', undefined, controller.signal)
		const refusal = expect(first).rejects.toThrow()
		if (mode === 'new-selection') {
			const second = surface.selectTarget('u/me/second')
			resolves.get('u/me/second')!({ workspaceRevision: descriptor.revision, copySource: { reference: 'u/me/second', kind: 'saved', revision: 'b'.repeat(64) } })
			await second
		} else if (mode === 'clear') surface.setTarget(undefined)
		else if (mode === 'load') surface.loaded()
		else if (mode === 'disconnect') surface.unbind()
		else controller.abort()
		resolves.get('u/me/first')!({ workspaceRevision: descriptor.revision, copySource: { reference: 'u/me/first', kind: 'saved', revision: 'a'.repeat(64) } })
		await refusal
		expect(surface.state.target?.reference).toBe(mode === 'new-selection' ? 'u/me/second' : undefined)
	})

	it.each([undefined, { reference: 'u/me/other', kind: 'saved', revision: 'a'.repeat(64) }, { reference: 'u/me/first', kind: 'saved', revision: '' }])('rejects missing or mismatched canonical source metadata', async copySource => {
		const surface = new EngineSurface(descriptor)
		surface.setApi({ contentRead: async () => ({ workspaceRevision: descriptor.revision, copySource }) } as NativeProvidersApi)
		await expect(surface.selectTarget('u/me/first')).rejects.toThrow()
		expect(surface.state.target).toBeUndefined()
	})
})
