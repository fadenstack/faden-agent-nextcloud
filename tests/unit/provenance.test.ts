import { describe, expect, it, vi } from 'vitest'
import { FakeGateway, lastToolResults } from '@fadenstack/testing'
import { ApprovalPart } from '@fadenstack/ui'
import { checkMarker, frontMatterMark, memorySink, withFrontMatter } from '@fadenstack/provenance'
import { createApp, markRaw, nextTick } from 'vue'
import AgentChat from '../../src/components/AgentChat.vue'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { FILE_METADATA, FILE_SINK, METADATA_KEY, NextcloudFileMarker, provenanceNote, type NextcloudFileSink } from '../../src/integrations/nextcloud/provenance.ts'
import type { FileApi, FileContext, Identity, Proposal, WriteMark } from '../../src/integrations/nextcloud/types.ts'
import vectors from '../fixtures/provenance/front-matter.json'

vi.mock('../../src/components/HeaderButton.vue', () => ({ default: { template: '<button>Fadenstack Agent</button>' } }))

const identity: Identity = { userId: 'alice', binding: 'binding', enabled: true, serverUrl: 'https://faden.test', agent: 'nc', development: false }
const selected: FileContext = { id: 'opaque-context', parentId: 1, expires: 9999999999, file: { id: 2, name: 'Agreement.md', path: '/Agreement.md', etag: 'revision-1', mime: 'text/markdown', size: 40, folder: false, permissions: 31, url: 'https://cloud.test/f/2' } }
const SUMMARY = '---\ntitle: Supplier agreements\n---\n# Summary\n\nThe three supplier agreements run until 2027. Each can be ended with three months\' written notice; the October agreement shortens that to thirty days.\n'
const CURRENT = 'The supplier agreement runs until the end of 2027. It can be ended with three months written notice by either side.'
const front = (text: string) => /^﻿?---\r?\n[\s\S]*?\r?\n(?:---|\.\.\.)(?:\r?\n|$)/.exec(text)?.[0] ?? null

/**
 * The PHP FileService as the client sees it: the commit writes the marked text only when it is the approved text with
 * the front matter mark, keeps the handed record as the file's metadata (unless `keepMetadata` is off), and reads both
 * back.
 */
function nextcloud(options: { keepMetadata?: boolean } = {}) {
	let proposal: Proposal | undefined
	const commits: { id: string; mark?: WriteMark; written: string }[] = []
	const metadata = new Map<number, string>()
	const files: FileApi = {
		identity: vi.fn(async () => identity), assertIdentity: vi.fn(async () => {}), select: vi.fn(async () => selected),
		read: vi.fn(async () => ({ file: selected.file, text: CURRENT, partial: false, totalCharacters: CURRENT.length, processedCharacters: CURRENT.length })),
		pdf: vi.fn(async () => new Uint8Array()), list: vi.fn(async () => ({ folder: { ...selected.file, folder: true }, files: [], partial: false })),
		propose: vi.fn(async (contextId, args) => (proposal = { id: 'proposal', contextId, ...args, filename: args.filename ?? selected.file.name, target: `/${args.filename ?? selected.file.name}`, before: args.operation === 'update' ? CURRENT : null })),
		approve: vi.fn(async () => {}),
		commit: vi.fn(async (id: string, _signal?: AbortSignal, mark?: WriteMark) => {
			const p = proposal!
			const record = mark?.provenance ? (JSON.parse(mark.provenance) as { record: { id: string } }).record.id : undefined
			const markdown = /\.md$/i.test(p.filename!)
			const written = record && markdown && mark?.content === withFrontMatter(p.content, record) ? mark.content : p.content
			commits.push({ id, ...(mark ? { mark } : {}), written })
			if (mark?.provenance && options.keepMetadata !== false) metadata.set(3, mark.provenance)
			return { operation: p.operation, file: { ...selected.file, id: 3, name: p.filename! }, provenance: { key: METADATA_KEY, metadata: metadata.get(3) ?? null, frontMatter: markdown ? front(written) : null } }
		}),
	}
	return { files, commits, metadata }
}

function agent(model: ConstructorParameters<typeof FakeGateway>[0]['model'], options: { gateway?: ConstructorParameters<typeof FakeGateway>[0]; keepMetadata?: boolean } = {}) {
	const gateway = new FakeGateway({ agents: [{ slug: 'nc', version: 1, contract: [
		{ name: 'nc_get_selected_file', risk: 'read' }, { name: 'nc_read_file', risk: 'read' },
		{ name: 'nc_create_text_file', risk: 'write' }, { name: 'nc_update_text_file', risk: 'write' },
	] }], users: [{ email: 'alice', password: 'test', agents: ['nc'] }], provenance: { receipts: true }, model, ...options.gateway })
	const fake = nextcloud(options)
	return { gateway, ...fake, host: new NextcloudAgent(identity, fake.files, gateway.fetch) }
}

/** Sends a message with the included file and allows each write it proposes, as the user does on the card. */
async function run(host: NextcloudAgent, text: string): Promise<void> {
	if (!host.chat.isReady) await host.signIn('alice', 'test')
	await host.selection.select(2)
	const allowed = new Set<string>()
	const approve = setInterval(() => {
		for (const part of host.chat.turns.flatMap(t => [...t.parts])) {
			if (!(part instanceof ApprovalPart) || !part.isPending || allowed.has(part.request.callId)) continue
			allowed.add(part.request.callId)
			void host.approve(part)
		}
	}, 5)
	try { await host.send(text, true) } finally { clearInterval(approve) }
}

function toolResult(gateway: FakeGateway, callId: string): Record<string, unknown> {
	const call = gateway.modelCalls.find(c => lastToolResults(c).has(callId))!
	return JSON.parse(lastToolResults(call).get(callId)!) as Record<string, unknown>
}

describe('Front matter, as the SDK writes it', () => {
	it.each(vectors.vectors.map(v => [v.name, v] as const))('%s: the SDK writes the bytes the PHP side checks against', (_name, vector) => {
		expect(withFrontMatter(vector.input, vectors.recordId)).toBe(vector.marked)
		expect(frontMatterMark(vector.head!)?.recordId).toBe(vectors.recordId)
	})
})

describe('NextcloudFileMarker', () => {
	it('passes the SDK marker test kit: the record handed to the write, read back from the metadata, nothing personal', async () => {
		const { sink: base, written } = memorySink({ kind: FILE_SINK, carries: [FILE_METADATA] })
		let handed: string | undefined
		let stored: string | null = null
		const sink: NextcloudFileSink = Object.assign(base, { fileRecord: { hand: (d: string) => { handed = d; written.files.set(METADATA_KEY, d) }, stored: () => stored } })
		const result = await checkMarker(new NextcloudFileMarker(), {
			sink,
			insert: () => { stored = handed ?? null },
			read: () => stored ? [{ carrier: FILE_METADATA, recordId: (JSON.parse(stored) as { record: { id: string } }).record.id }] : [],
		})
		expect(result.problems).toEqual([])
		expect(result.outcome).toEqual({ applied: true, method: FILE_METADATA, verified: true })
		expect(JSON.parse(handed!)).toMatchObject({ contract: 'provenance/v1', record: { id: result.recordId } })
	})
	it('reports a record the metadata did not keep as stripped by the host, and one not read back as unverified', async () => {
		const marker = new NextcloudFileMarker()
		const placed = (stored: string | null | undefined) => ({ sink: { kind: FILE_SINK, carries: [FILE_METADATA], fileRecord: { hand: () => {}, stored: () => stored } } }) as never
		const record = { id: '5a0e2b5c-7f3e-4a51-9a40-6a1c55e1d3f1' } as never
		expect(marker.attach(placed(null), record)).toMatchObject({ applied: false, reason: 'stripped-by-host' })
		expect(marker.attach(placed('{"record":{"id":"0b6f9a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b"}}'), record)).toMatchObject({ applied: false, reason: 'stripped-by-host' })
		expect(marker.attach(placed(undefined), record)).toMatchObject({ applied: true, verified: false })
		expect(marker.supports({ kind: 'html.editor', carries: [FILE_METADATA] })).toBe(false)
	})
})

describe('Files the agent writes are marked as made with AI', () => {
	it('a new Markdown note: the record in the file metadata, the mark merged into its front matter, both read back', async () => {
		let round = 0
		const { host, gateway, commits } = agent(() => ++round === 1
			? { toolCalls: [{ name: 'nc_create_text_file', arguments: { filename: 'Summary.md', content: SUMMARY, edit_kind: 'summarised' }, id: 'write' }] }
			: { text: 'Created Summary.md.' })
		await run(host, 'Summarise the agreements into a note')
		expect(gateway.provenanceRecords).toHaveLength(1)
		const signed = gateway.provenanceRecords[0]!.signed
		expect(gateway.provenanceRecords[0]!.request).toMatchObject({ media_type: 'text/markdown', edit_kind: 'summarised' })
		const id = signed.record.id
		expect(commits).toHaveLength(1)
		expect(commits[0]!.mark!.content).toBe(withFrontMatter(SUMMARY, id))
		expect(commits[0]!.written.startsWith(`---\ntitle: Supplier agreements\nai-generated: true\nai-record-id: "${id}"\n---\n# Summary\n`)).toBe(true)
		// The record goes to FileService as the SDK's sidecar document: contract, the record and its signature as signed.
		expect(JSON.parse(commits[0]!.mark!.provenance!)).toEqual({ contract: 'provenance/v1', record: signed.record, signature: signed.signature })
		const report = gateway.placements[0]!.report
		expect(report).toMatchObject({ record_id: id, tool: 'nc_create_text_file', sink_kind: 'nextcloud.file', media_type: 'text/markdown', edit_kind: 'summarised' })
		expect(report.layers).toEqual(expect.arrayContaining([
			{ layer: 'metadata', method: 'front-matter', applied: true, verified: true },
			{ layer: 'metadata', method: 'nextcloud-metadata', applied: true, verified: true },
		]))
		const result = toolResult(gateway, 'write')
		expect(result.provenance).toEqual({ record_id: id, marks: ['front-matter', 'nextcloud-metadata'], exempt: null, missing: expect.any(Array) })
		expect(JSON.stringify(result)).not.toContain(signed.signature) // the model gets ids and marks, not the record
		expect(host.placement('write')?.record_id).toBe(id)
		expect(provenanceNote(host.placement('write')!)).toBe('metadata-and-front-matter')
		await host.dispose()
	})
	it('a text file: the record in its metadata only, the text as approved', async () => {
		let round = 0
		const reply = 'Dear Ms Weber, your order of 500 pressure sensors ships in March. We will confirm the date a week before.'
		const { host, gateway, commits } = agent(() => ++round === 1
			? { toolCalls: [{ name: 'nc_create_text_file', arguments: { filename: 'Reply.txt', content: reply }, id: 'write' }] }
			: { text: 'Created Reply.txt.' })
		await run(host, 'Write the reply as a text file')
		expect(gateway.provenanceRecords[0]!.request).toMatchObject({ media_type: 'text/plain', edit_kind: 'generated' })
		expect(commits[0]!.written).toBe(reply)
		expect(commits[0]!.mark!.content).toBe(reply)
		expect(host.placement('write')).toMatchObject({ marks: ['nextcloud-metadata'], missing: [] })
		expect(provenanceNote(host.placement('write')!)).toBe('metadata')
		await host.dispose()
	})
	it('the server cannot sign: the file is written as approved, unmarked, and that is said', async () => {
		let round = 0
		const { host, gateway, commits } = agent(() => ++round === 1
			? { toolCalls: [{ name: 'nc_create_text_file', arguments: { filename: 'Summary.md', content: SUMMARY }, id: 'write' }] }
			: { text: 'Created.' }, { gateway: { provenance: { down: true } } })
		await run(host, 'Summarise the agreements into a note')
		expect(gateway.provenanceRecords).toHaveLength(0)
		expect(commits).toHaveLength(1)
		expect(commits[0]!.mark).toEqual({ content: SUMMARY })
		expect(commits[0]!.written).toBe(SUMMARY)
		expect(host.placement('write')).toMatchObject({ record_id: null, marks: [], missing: [{ layer: 'metadata', method: 'signed-record', reason: 'server-unavailable' }] })
		expect(provenanceNote(host.placement('write')!)).toBe('unsigned')
		await host.dispose()
	})
	it('a server without the provenance module: the write goes on unmarked', async () => {
		let round = 0
		const { host, commits } = agent(() => ++round === 1
			? { toolCalls: [{ name: 'nc_create_text_file', arguments: { filename: 'Reply.txt', content: CURRENT }, id: 'write' }] }
			: { text: 'Created.' }, { gateway: { provenance: { enabled: false } } })
		await run(host, 'Write it down')
		expect(commits[0]!.written).toBe(CURRENT)
		expect(host.placement('write')?.record_id).toBeNull()
		await host.dispose()
	})
	it('Nextcloud did not keep the metadata: the placement says so', async () => {
		let round = 0
		const { host, gateway } = agent(() => ++round === 1
			? { toolCalls: [{ name: 'nc_create_text_file', arguments: { filename: 'Reply.txt', content: CURRENT }, id: 'write' }] }
			: { text: 'Created.' }, { keepMetadata: false })
		await run(host, 'Write it down')
		expect(gateway.placements[0]!.report.layers).toContainEqual({ layer: 'metadata', method: 'nextcloud-metadata', applied: false, reason: 'stripped-by-host' })
		expect(host.placement('write')?.missing).toEqual([{ layer: 'metadata', method: 'nextcloud-metadata', reason: 'stripped-by-host' }])
		expect(provenanceNote(host.placement('write')!)).toBe('not-kept')
		await host.dispose()
	})
	it('small corrections are exempt and a rewrite is marked, as the SDK\'s similarity classifier decides', async () => {
		const corrected = CURRENT.replace('written notice', 'written notice,')
		const rewritten = 'Both parties may now end the agreement at any time. A new price list applies from January; deliveries move to Fridays.'
		for (const [content, exempt] of [[corrected, true], [rewritten, false]] as const) {
			let round = 0
			const { host, gateway, commits } = agent(() => {
				round++
				if (round === 1) return { toolCalls: [{ name: 'nc_read_file', arguments: {}, id: 'read' }] }
				if (round === 2) return { toolCalls: [{ name: 'nc_update_text_file', arguments: { content, expectedEtag: 'revision-1', edit_kind: 'corrected' }, id: 'write' }] }
				return { text: 'Updated.' }
			})
			await run(host, 'Fix the punctuation of the included file')
			expect(commits[0]!.written).toBe(exempt ? content : withFrontMatter(content, gateway.provenanceRecords[0]!.signed.record.id))
			if (exempt) {
				expect(gateway.provenanceRecords).toHaveLength(0)
				expect(host.placement('write')).toMatchObject({ record_id: null, exempt: 'edit-kind' })
				expect(gateway.placements[0]!.report).toMatchObject({ edit_kind: 'corrected', layers: [{ layer: 'metadata', method: 'record', applied: false, reason: 'exempt-edit' }] })
			} else {
				expect(gateway.provenanceRecords[0]!.request).toMatchObject({ edit_kind: 'rewritten', media_type: 'text/markdown' })
				expect(host.placement('write')?.marks).toEqual(['front-matter', 'nextcloud-metadata'])
			}
			await host.dispose()
		}
	})
	it('a translation is exempt and recorded as such', async () => {
		let round = 0
		const { host, gateway, commits } = agent(() => ++round === 1
			? { toolCalls: [{ name: 'nc_create_text_file', arguments: { filename: 'Vertrag.md', content: SUMMARY, edit_kind: 'translated' }, id: 'write' }] }
			: { text: 'Übersetzt.' })
		await run(host, 'Translate the agreement into German as a new note')
		expect(gateway.provenanceRecords).toHaveLength(0)
		expect(commits[0]!.written).toBe(SUMMARY)
		expect(host.placement('write')).toMatchObject({ exempt: 'edit-kind' })
		expect(provenanceNote(host.placement('write')!)).toBe('exempt')
		await host.dispose()
	})
	it('the manifest declares what the write tools write', async () => {
		const { host, gateway } = agent(() => ({ text: 'Hello' }))
		await host.signIn('alice', 'test')
		await vi.waitFor(() => expect(gateway.manifests).toHaveLength(1))
		// On the wire a list per tool; the edit kind is decided per call (`edit_kind`), so it is not named.
		const tools = gateway.manifests[0]!.manifest.tools as { name: string; outputs?: unknown }[]
		expect(tools.filter(t => t.outputs).map(t => [t.name, t.outputs])).toEqual([
			['nc_create_text_file', [{ argument: 'content', media_type: 'text/markdown' }]],
			['nc_update_text_file', [{ argument: 'content', media_type: 'text/markdown' }]],
		])
		await host.dispose()
	})
	it('the panel says under the write how the file is marked', async () => {
		vi.stubGlobal('ResizeObserver', class { observe() {} disconnect() {} })
		let round = 0
		const { host } = agent(() => ++round === 1
			? { toolCalls: [{ name: 'nc_create_text_file', arguments: { filename: 'Summary.md', content: SUMMARY }, id: 'write' }] }
			: { text: 'Created Summary.md.' })
		const root = document.createElement('div')
		document.body.append(root)
		await host.signIn('alice', 'test')
		const app = createApp(AgentChat, { host: markRaw(host) })
		app.mount(root)
		try {
			await run(host, 'Summarise the agreements into a note')
			await nextTick()
			await vi.waitFor(() => expect(root.querySelector('.fdn-provenance-note')?.textContent?.trim()).toBe('Marked as AI-generated in the file\'s metadata and its front matter.'))
		} finally { app.unmount(); root.remove(); await host.dispose(); vi.unstubAllGlobals() }
	})
})
