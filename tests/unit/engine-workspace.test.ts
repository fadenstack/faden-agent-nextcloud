import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, markRaw } from 'vue'
import { EngineSurface, engineSurfaceDescriptor } from '../../src/workspace/engineSurface.ts'
import { WorkspaceCoordinator } from '../../src/workspace/coordinator.ts'
import EngineWorkspacePane from '../../src/components/EngineWorkspacePane.vue'
import { engineWorkspaceLinks } from '../../src/providers/engineConnections.ts'
const descriptor = { connectionId: 'own-engine', provider: 'windmill' as const, label: 'Own Windmill', url: 'https://engine.test/?workspace=own', origin: 'https://engine.test', revision: 'a'.repeat(64) }
afterEach(() => document.body.replaceChildren())
describe('Native workflow workspace handoff', () => {
	it('does not equate an opaque editor or an explicit pause with clean native content', () => {
		const surface = new EngineSurface(descriptor), coordinator = new WorkspaceCoordinator({ guard: { snapshot: () => surface.snapshot(), dispose() {} }, refresh: vi.fn() })
		expect(() => coordinator.prepare('windmill')).toThrow('unsaved')
		surface.pause(); expect(surface.snapshot().editState).toBe('unknown')
		const binding = coordinator.prepare('windmill')
		surface.resume(); expect(() => coordinator.begin('stale', binding)).toThrow('view changed')
	})
	it('accepts only the paused engine handoff and never automatically refreshes it after commitment', async () => {
		const surface = new EngineSurface(descriptor), refresh = vi.fn()
		const coordinator = new WorkspaceCoordinator({ guard: { snapshot: () => surface.snapshot(), dispose() {} }, refresh })
		surface.pause(); const binding = coordinator.prepare('windmill'), generation = coordinator.begin('draft', binding)
		const receipt = await coordinator.committed('draft', generation, binding, { operation: 'nc_windmill_draft', file: { id: 0, name: 'New private draft', path: '', mime: '', size: 0, etag: '', folder: false, permissions: 0, url: '' }, provider: { app: 'windmill', collectionId: 'own-engine', filename: 'draft' } }, new AbortController().signal)
		expect(receipt.view).toBe('refresh_required'); expect(refresh).not.toHaveBeenCalled(); expect(coordinator.state.locked).toBe(false)
	})
	it('invalidates approval on native load and refuses a broken workspace', () => {
		const surface = new EngineSurface(descriptor), coordinator = new WorkspaceCoordinator({ guard: { snapshot: () => surface.snapshot(), dispose() {} }, refresh: vi.fn() })
		surface.pause(); const binding = coordinator.prepare('windmill'); surface.loaded()
		expect(() => coordinator.begin('stale-load', binding)).toThrow('view changed')
		const broken = new WorkspaceCoordinator({ guard: { snapshot: () => ({ app: 'engine-workspace', key: 'broken', epoch: 0, editState: 'unknown', reason: 'Unavailable' }), dispose() {} }, refresh: vi.fn() })
		expect(() => broken.prepare('n8n')).toThrow('Unavailable')
	})
	it('preserves the same child while paused and reloads only that child after a user acknowledgement', async () => {
		const surface = new EngineSurface(descriptor), root = document.createElement('div'); document.body.append(root)
		const app = createApp(EngineWorkspacePane, { surface: markRaw(surface) }); app.mount(root)
		try {
			const frame = root.querySelector('iframe')!
			surface.pause(); await vi.waitFor(() => expect(frame.hasAttribute('inert')).toBe(true)); expect(root.querySelector('iframe')).toBe(frame)
			surface.resume(); await vi.waitFor(() => expect(frame.hasAttribute('inert')).toBe(false)); expect(root.querySelector('iframe')).toBe(frame)
			surface.reloadAcknowledged(); await vi.waitFor(() => expect(root.querySelector('iframe')).not.toBe(frame))
			expect(root.textContent).toContain('state is unknown'); expect(root.querySelector('a')?.rel).toBe('noopener noreferrer')
		} finally { app.unmount() }
	})
	it('uses the SDK busy state to lock human handoff controls and releases it on unbind', () => {
		const surface = new EngineSurface(descriptor); let notify = () => {}; const unsubscribe = vi.fn(), chat = { isBusy: false, subscribe: (fn: () => void) => { notify = fn; return unsubscribe } }
		surface.bind(chat); chat.isBusy = true; notify(); surface.pause(); surface.reloadAcknowledged(); expect(surface.state.paused).toBe(false); expect(surface.state.generation).toBe(0)
		surface.unbind(); expect(unsubscribe).toHaveBeenCalledOnce(); expect(surface.state.busy).toBe(false)
	})
	it('refuses credential-bearing descriptors and foreign or substituted native workspace links', () => {
		expect(() => engineSurfaceDescriptor({ ...descriptor, url: 'https://user:password@engine.test/' })).toThrow()
		expect(() => engineSurfaceDescriptor({ ...descriptor, origin: 'https://other.test' })).toThrow()
		expect(() => engineSurfaceDescriptor({ ...descriptor, apiToken: 'untrusted' })).toThrow()
		expect(() => engineWorkspaceLinks({ workspaces: [{ connectionId: 'own-engine', href: 'https://foreign.test/apps/fadenstack_agent/engines/workspace/own-engine' }] })).toThrow()
		expect(() => engineWorkspaceLinks({ workspaces: [{ connectionId: 'own-engine', href: '/apps/fadenstack_agent/engines/workspace/other' }] })).toThrow()
	})
})
