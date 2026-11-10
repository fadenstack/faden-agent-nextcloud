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
	it('supports explicit target selection, surfaces API principal, and invalidates approvals on target change', async () => {
		const surface = new EngineSurface({ ...descriptor, apiPrincipal: 'admin@windmill.local' })
		expect(surface.apiPrincipal).toBe('admin@windmill.local')
		const mockApi = {
			contentRead: vi.fn().mockImplementation((_provider, params) => {
				if (!params?.reference) {
					return Promise.resolve({
						workflows: [
							{ reference: 'u/admin/flow1', name: 'flow1', revision: 'r1' },
							{ reference: 'f/global/flow2', name: 'flow2', revision: 'r2' },
						],
					})
				}
				return Promise.resolve({
					copySource: { reference: params.reference, kind: params.sourceKind || 'flow', revision: 'rev-read-1' },
					definition: { name: 'flow1' },
				})
			}),
		}
		surface.setApi(mockApi as any)
		const list = await surface.loadWorkflows()
		expect(list).toHaveLength(2)
		expect(list[0].reference).toBe('u/admin/flow1')

		await surface.selectTarget('u/admin/flow1', 'flow')
		expect(surface.state.target).toEqual({
			connectionId: 'own-engine',
			provider: 'windmill',
			reference: 'u/admin/flow1',
			sourceKind: 'flow',
			name: 'flow1',
			revision: 'rev-read-1',
			apiPrincipal: 'admin@windmill.local',
			copySource: {
				kind: 'flow',
				reference: 'u/admin/flow1',
				revision: 'rev-read-1',
			},
		})

		const coordinator = new WorkspaceCoordinator({ guard: { snapshot: () => surface.snapshot(), dispose() {} }, refresh: vi.fn() })
		surface.pause()
		const binding = coordinator.prepare('windmill')

		// Changing target invalidates approval binding via snapshot key
		await surface.selectTarget('f/global/flow2', 'flow')
		expect(() => coordinator.begin('stale-target', binding)).toThrow('view changed')
	})
	it('distinguishes original source from newly created copy and constructs safe engine URLs', () => {
		const surface = new EngineSurface(descriptor)
		surface.recordResult({
			operation: 'nc_windmill_draft',
			sourceReference: 'f/original',
			createdReference: 'f/original',
			sourceKind: 'flow',
			connectionId: 'own-engine',
			isCopy: false,
		})
		expect(surface.state.lastResult?.isCopy).toBe(false)

		surface.recordResult({
			operation: 'nc_windmill_draft',
			sourceReference: 'f/original',
			createdReference: 'u/admin/draft_copy',
			sourceKind: 'flow',
			connectionId: 'own-engine',
			isCopy: true,
		})
		expect(surface.state.lastResult?.isCopy).toBe(true)
		expect(surface.state.lastResult?.createdReference).toBe('u/admin/draft_copy')

		// Windmill target URLs
		expect(surface.targetUrl('u/admin/draft_copy')).toBe('https://engine.test/flows/edit/u/admin/draft_copy?workspace=own')
		expect(surface.targetUrl('f/original')).toBe('https://engine.test/flows/edit/f/original?workspace=own')

		// n8n target URLs
		const n8nSurface = new EngineSurface({
			connectionId: 'n8n-own',
			provider: 'n8n',
			label: 'Own n8n',
			url: 'https://n8n.test/workflow',
			origin: 'https://n8n.test',
			revision: 'b'.repeat(64),
		})
		expect(n8nSurface.targetUrl('wf-456')).toBe('https://n8n.test/workflow/wf-456')

		// Child navigation origin validation
		surface.navigateChild('https://engine.test/flows/edit/u/admin/draft_copy?workspace=own')
		expect(surface.state.currentUrl).toBe('https://engine.test/flows/edit/u/admin/draft_copy?workspace=own')
		expect(() => surface.navigateChild('https://malicious.test/exploit')).toThrow('Invalid child navigation destination.')
	})
	it('models refresh authorization distinctly and binds it to a single generation', () => {
		const surface = new EngineSurface(descriptor)
		surface.pause()
		expect(surface.snapshot().editState).toBe('unknown')
		expect(surface.snapshot().refreshAuthorized).toBe(false)

		surface.authorizeRefresh(true)
		expect(surface.snapshot().refreshAuthorized).toBe(true)
		expect(surface.snapshot().editState).toBe('unknown') // Still unknown, never fake clean

		// Resuming clears refresh authorization
		surface.resume()
		expect(surface.state.refreshAuthorized).toBe(false)

		// Navigation clears refresh authorization
		surface.pause()
		surface.authorizeRefresh(true)
		surface.navigateChild('https://engine.test/?workspace=own#/flow/u/admin/test')
		expect(surface.state.refreshAuthorized).toBe(false)

		// Child load clears refresh authorization
		surface.authorizeRefresh(true)
		surface.loaded()
		expect(surface.state.refreshAuthorized).toBe(false)
	})
	it('handles child-only refresh for Windmill and preserves SAMEORIGIN fallback for n8n', async () => {
		const surface = new EngineSurface(descriptor)
		surface.authorizeRefresh(true)
		const windmillResult = await surface.refresh({
			operation: 'nc_windmill_draft',
			file: { id: 0, name: 'draft', path: '', mime: '', size: 0, etag: '', folder: false, permissions: 0, url: '' },
			provider: { app: 'windmill', collectionId: 'own-engine', filename: 'u/admin/draft_flow' },
		})
		expect(windmillResult).toBe('refreshed')
		expect(surface.state.currentUrl).toBe('https://engine.test/flows/edit/u/admin/draft_flow?workspace=own')

		const n8nSurface = new EngineSurface({
			connectionId: 'n8n-own',
			provider: 'n8n',
			label: 'Own n8n',
			url: 'https://n8n.test/workflow',
			origin: 'https://n8n.test',
			revision: 'b'.repeat(64),
		})
		n8nSurface.authorizeRefresh(true)
		const n8nResult = await n8nSurface.refresh({
			operation: 'nc_n8n_draft',
			file: { id: 0, name: 'wf', path: '', mime: '', size: 0, etag: '', folder: false, permissions: 0, url: '' },
			provider: { app: 'n8n', collectionId: 'n8n-own', filename: 'wf-123' },
		})
		expect(n8nResult).toBe('refresh_required')
	})
	it('preserves committed receipt even when optional refresh fails or is aborted without repeating mutations', async () => {
		const surface = new EngineSurface(descriptor)
		surface.pause()
		surface.authorizeRefresh(true)

		const failingRefresh = vi.fn().mockRejectedValue(new Error('Child refresh timed out'))
		const coordinator = new WorkspaceCoordinator({
			guard: { snapshot: () => surface.snapshot(), dispose() {} },
			refresh: failingRefresh,
		})
		const binding = coordinator.prepare('windmill')
		const generation = coordinator.begin('draft-fail', binding)
		const receipt = await coordinator.committed(
			'draft-fail',
			generation,
			binding,
			{ operation: 'nc_windmill_draft', file: { id: 1, name: 'draft', path: '', mime: '', size: 0, etag: '', folder: false, permissions: 0, url: '' }, provider: { app: 'windmill', collectionId: 'own-engine', filename: 'draft' } },
			new AbortController().signal,
		)
		// Committed outcome is preserved!
		expect(receipt.outcome).toBe('committed')
		expect(receipt.view).toBe('refresh_failed')

		// Stop/abort before refresh
		const abortedController = new AbortController()
		abortedController.abort()
		const generation2 = coordinator.begin('draft-abort', binding)
		const abortedReceipt = await coordinator.committed(
			'draft-abort',
			generation2,
			binding,
			{ operation: 'nc_windmill_draft', file: { id: 2, name: 'draft2', path: '', mime: '', size: 0, etag: '', folder: false, permissions: 0, url: '' }, provider: { app: 'windmill', collectionId: 'own-engine', filename: 'draft2' } },
			abortedController.signal,
		)
		expect(abortedReceipt.outcome).toBe('committed')
		expect(abortedReceipt.view).toBe('refresh_required')
	})
	it('rejects late responses and overlapping generations', async () => {
		const surface = new EngineSurface(descriptor)
		surface.pause()
		const coordinator = new WorkspaceCoordinator({ guard: { snapshot: () => surface.snapshot(), dispose() {} }, refresh: vi.fn() })
		const binding = coordinator.prepare('windmill')
		const g1 = coordinator.begin('op1', binding)

		// Surface reloads acknowledged
		surface.reloadAcknowledged()
		surface.pause()
		const binding2 = coordinator.prepare('windmill')
		coordinator.notDispatched('op1', g1, 'target', 'superseded', 'nc_windmill_draft')

		const g2 = coordinator.begin('op2', binding2)
		// Late attempt to commit with old generation g1
		await expect(coordinator.committed('op1', g1, binding, { operation: 'nc_windmill_draft', file: { id: 1, name: 'draft', path: '', mime: '', size: 0, etag: '', folder: false, permissions: 0, url: '' }, provider: { app: 'windmill', collectionId: 'own-engine', filename: 'draft' } }, new AbortController().signal)).rejects.toThrow('no longer matches')
	})
	it('renders accessible controls, API principal badge, and target selector in narrow layout', async () => {
		const surface = new EngineSurface({ ...descriptor, apiPrincipal: 'operator@windmill.test' })
		surface.setTarget({
			connectionId: 'own-engine',
			provider: 'windmill',
			reference: 'u/operator/test_flow',
			sourceKind: 'flow',
			name: 'test_flow',
		})
		surface.recordResult({
			operation: 'nc_windmill_draft',
			sourceReference: 'f/main_flow',
			createdReference: 'u/operator/test_flow',
			sourceKind: 'flow',
			connectionId: 'own-engine',
			isCopy: true,
		})

		const root = document.createElement('div')
		document.body.append(root)
		const app = createApp(EngineWorkspacePane, { surface: markRaw(surface) })
		app.mount(root)
		try {
			// API account badge
			expect(root.textContent).toContain('operator@windmill.test')
			// Unobservable editor badge
			expect(root.textContent).toContain('Editor: unobservable')
			// Result copy banner
			expect(root.textContent).toContain('New copy: u/operator/test_flow')
			// Check toolbar has accessible controls
			const toolbar = root.querySelector('[role="toolbar"]')
			expect(toolbar).not.toBeNull()
			const buttons = root.querySelectorAll('button')
			expect(buttons.length).toBeGreaterThan(3)
			for (const button of buttons) {
				const hasAccessibleName = button.hasAttribute('aria-label') || !!button.textContent?.trim()
				expect(hasAccessibleName).toBe(true)
			}
		} finally {
			app.unmount()
		}
	})
})
