// Files the agent writes are marked as made with AI, in a real loopback Nextcloud (sdk/14 §4): the production host,
// tools, approval path and provenance module write a Markdown note and a text file; Nextcloud keeps the signed record
// as file metadata through rename, move, copy (the app's listener), a folder copy, a new version, a share and the
// trash, and the note carries the mark in its front matter. The SDK's fake gateway is the model and signs records.
//   node --experimental-transform-types tests/integration/provenance.mjs
// With FADENSTACK_PROVENANCE_SERVER, _AGENT and _TOKEN (an agent's or a maintainer's token, from the environment
// only) that server's provenance module signs the records instead, and its detector's verdict on the note is reported.
// NODE_EXTRA_CA_CERTS names its CA when it has its own. File ids and record ids go to stdout; no token is printed.
import assert from 'node:assert/strict'
import { FakeGateway, lastToolResults } from '@fadenstack/testing'
import { frontMatterMark, markdownText, withFrontMatter } from '@fadenstack/provenance'
import { ApprovalPart } from '@fadenstack/ui'
import { User, base, metrics } from './client.mjs'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'

const KEY = 'fadenstack-provenance'
const SUMMARY = '---\ntitle: Supplier agreements\n---\n# Summary\n\nThe three supplier agreements run until 2027. Each can be ended with three months\' written notice; the October agreement shortens that to thirty days. Prices are fixed until June.\n'
const REPLY = 'Dear Ms Weber,\n\nyour order of 500 pressure sensors ships in March. We will confirm the delivery date one week before.\n\nKind regards'

const alice = new User('pilot-alice'), bob = new User('pilot-bob')
await alice.login(); await bob.login()
const version = (await (await alice.request('/status.php')).json()).versionstring
const folder = `provenance-${Date.now()}`
assert.equal((await alice.dav(folder, 'MKCOL')).status, 201)
assert.equal((await alice.dav(`${folder}/Agreements.md`, 'PUT', '# Agreements\n\nThree supplier agreements.')).status, 201)
const source = await alice.id(`${folder}/Agreements.md`)

const decode = text => text.replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&')
/** The record the file's metadata holds, over WebDAV (`nc:metadata-fadenstack-provenance`), and the file id. */
async function metadata(user, path) {
  const response = await user.dav(path, 'PROPFIND', `<d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/><nc:metadata-${KEY}/></d:prop></d:propfind>`, { 'Content-Type': 'text/xml', Depth: '0' })
  assert.equal(response.status, 207, `${path}: ${response.status}`)
  const xml = await response.text()
  const value = new RegExp(`<nc:metadata-${KEY}[^>]*>([^<]*)</nc:metadata-${KEY}>`).exec(xml)?.[1]
  return { fileId: Number(/<oc:fileid>(\d+)<\/oc:fileid>/.exec(xml)?.[1]), record: value ? JSON.parse(decode(value)) : undefined }
}
async function text(user, path) {
  const response = await user.dav(path, 'GET')
  assert.equal(response.status, 200, `${path}: ${response.status}`)
  return response.text()
}
const rows = []
function row(operation, what) { rows.push([operation, what]); console.log(`  ${operation}: ${what}`); metrics.checks++ }

const real = process.env.FADENSTACK_PROVENANCE_SERVER?.replace(/\/$/, '')
const token = process.env.FADENSTACK_PROVENANCE_TOKEN
delete process.env.FADENSTACK_PROVENANCE_TOKEN
const realAgent = process.env.FADENSTACK_PROVENANCE_AGENT
if (real) assert.ok(token && realAgent, 'A real server needs FADENSTACK_PROVENANCE_AGENT and FADENSTACK_PROVENANCE_TOKEN.')
// The model: each send runs the next calls; the fake gateway signs records unless a real server does. Its answers
// carry receipts only without a real server, which would not keep a record naming a receipt it never issued.
const script = []
const gateway = new FakeGateway({
  agents: [{ slug: 'nc', version: 1, contract: [{ name: 'nc_get_selected_file', risk: 'read' }, { name: 'nc_read_file', risk: 'read' }, { name: 'nc_create_text_file', risk: 'write' }, { name: 'nc_update_text_file', risk: 'write' }] }],
  users: [{ email: 'alice@example.org', password: 'test', agents: ['nc'] }],
  provenance: { receipts: !real },
  model: request => { const next = script.shift(); return typeof next === 'function' ? next(request) : next ?? { text: 'Done.' } },
})
/** With a real server: its policy and its signed records; everything else (the model, placement reports) stays fake. */
/** The calls that went to the real server: route, status, and the record id it signed. */
const forwarded = []
const fetchImpl = !real ? gateway.fetch : async (input, init) => {
  const url = new URL(String(input instanceof Request ? input.url : input))
  const route = /^\/v1\/agents\/[^/]+\/provenance\/(policy|records)$/.exec(url.pathname)
  if (!route) return gateway.fetch(input, init)
  const body = input instanceof Request && init?.body === undefined ? await input.text() : init?.body
  const response = await fetch(`${real}/v1/agents/${encodeURIComponent(realAgent)}/provenance/${route[1]}`, {
    method: init?.method ?? (input instanceof Request ? input.method : 'GET'), body, signal: init?.signal,
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
  })
  const answer = await response.clone().json().catch(() => undefined)
  forwarded.push({ route: route[1], status: response.status, record: answer?.record?.id, detail: response.ok ? undefined : JSON.stringify(answer).slice(0, 200) })
  return response
}
const files = alice.files()
const written = []
const commit = files.commit
files.commit = async (id, signal, mark) => { const result = await commit(id, signal, mark); written.push(result); return result }
const host = new NextcloudAgent({ ...alice.config, serverUrl: gateway.serverUrl, agent: 'nc', development: false }, files, fetchImpl)
await host.signIn('alice@example.org', 'test')
async function send(message, include = source) {
  await host.selection.select(include)
  const allowed = new Set()
  const approve = setInterval(() => {
    for (const part of host.chat.turns.flatMap(turn => [...turn.parts])) {
      if (!(part instanceof ApprovalPart) || !part.isPending || allowed.has(part.request.callId)) continue
      allowed.add(part.request.callId)
      void host.approve(part)
    }
  }, 20)
  try { await host.send(message, true) } finally { clearInterval(approve) }
}

console.log(`Nextcloud ${version} at ${base}; records signed by ${real ? `${real} (agent ${realAgent})` : 'the fake gateway'}`)
script.push({ toolCalls: [{ name: 'nc_create_text_file', arguments: { filename: 'Summary.md', content: SUMMARY, edit_kind: 'summarised' }, id: 'summary' }] },
  { toolCalls: [{ name: 'nc_create_text_file', arguments: { filename: 'Reply.txt', content: REPLY }, id: 'reply' }] })
await send('Summarise the agreements into a note, and write the reply as a text file')
const summary = host.placement('summary'), reply = host.placement('reply')
assert.ok(summary?.record_id && reply?.record_id, `placements: ${JSON.stringify([summary, reply])}`)
assert.deepEqual(summary.marks, ['front-matter', 'nextcloud-metadata'])
assert.deepEqual(reply.marks, ['nextcloud-metadata'])
let id = summary.record_id
row('Created Summary.md through the agent', `record ${id}; marks read back: ${summary.marks.join(', ')}`)
row('Created Reply.txt through the agent', `record ${reply.record_id}; marks read back: ${reply.marks.join(', ')}`)
if (real) {
  // The records are the real server's: it answered the sign requests with these ids.
  const signedThere = forwarded.filter(call => call.route === 'records' && call.status === 200).map(call => call.record)
  assert.ok(signedThere.includes(id) && signedThere.includes(reply.record_id), `forwarded: ${JSON.stringify(forwarded)}`)
  assert.equal(gateway.provenanceRecords.length, 0, 'the fake gateway signed nothing')
  row(`Signed by ${real}`, forwarded.map(call => `${call.route} ${call.status}${call.record ? ` ${call.record}` : ''}`).join('; '))
}

let path = `${folder}/Summary.md`
const created = await metadata(alice, path)
assert.equal(created.record?.contract, 'provenance/v1')
assert.equal(created.record.record.id, id)
assert.equal(created.fileId, written[0].file.id)
const note = await text(alice, path)
assert.equal(note, withFrontMatter(SUMMARY, id))
assert.equal(frontMatterMark(note)?.recordId, id)
assert.ok(note.startsWith(`---\ntitle: Supplier agreements\nai-generated: true\nai-record-id: "${id}"\n---\n# Summary`), note.slice(0, 160))
row('File metadata over WebDAV', `nc:metadata-${KEY} holds the signed record (contract, record ${id}, signature${created.record.timestamp ? ', time stamp' : ''})`)
row('Front matter', 'merged after the note\'s own field: ai-generated: true, ai-record-id')
const replyMeta = await metadata(alice, `${folder}/Reply.txt`)
assert.equal(replyMeta.record?.record.id, reply.record_id)
assert.equal(await text(alice, `${folder}/Reply.txt`), REPLY)
row('Reply.txt', 'metadata holds its record; the text is exactly as approved')

// A replacement: the model reads the marked note and rewrites it, keeping the front matter it read; the new
// record replaces the earlier one in the metadata and in the front matter. The user attaches the note.
const REWRITTEN = '# Summary\n\nAll three supplier agreements were renegotiated in October: they now run until 2029, each can be ended with sixty days\' notice, and the new price list applies from January.\n'
let rewritten
script.push({ toolCalls: [{ name: 'nc_read_file', arguments: {}, id: 'read' }] }, request => {
  const read = JSON.parse(lastToolResults(request).get('read'))
  rewritten = read.text.replace(/# Summary[\s\S]*$/, REWRITTEN)
  assert.ok(rewritten.includes(`ai-record-id: "${id}"`), 'the model saw the earlier mark')
  return { toolCalls: [{ name: 'nc_update_text_file', arguments: { content: rewritten, expectedEtag: read.file.etag, edit_kind: 'rewritten' }, id: 'update' }] }
})
await send('Rewrite the attached note with the renegotiated terms', created.fileId)
const update = host.placement('update')
assert.ok(update?.record_id && update.record_id !== id, `update: ${JSON.stringify(update)}`)
assert.deepEqual(update.marks, ['front-matter', 'nextcloud-metadata'])
const replaced = await text(alice, path)
assert.equal(replaced, withFrontMatter(rewritten, update.record_id))
assert.ok(!replaced.includes(id), 'the earlier record id is gone from the front matter')
const replacedMeta = await metadata(alice, path)
assert.equal(replacedMeta.record?.record.id, update.record_id)
assert.equal(replacedMeta.record.record.edit_kind, 'rewritten')
assert.equal(replacedMeta.fileId, created.fileId)
row('Replaced through the agent (nc_update_text_file)', `new record ${update.record_id} (rewritten) in the metadata and the front matter; the earlier one replaced`)
id = update.record_id

// Nobody changes the record over WebDAV: the key is the server's.
const patch = await alice.dav(path, 'PROPPATCH', `<d:propertyupdate xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns"><d:set><d:prop><nc:metadata-${KEY}>{}</nc:metadata-${KEY}></d:prop></d:set></d:propertyupdate>`, { 'Content-Type': 'text/xml' })
const patched = await patch.text()
assert.ok(patch.status !== 207 || !/HTTP\/1\.1 200/.test(patched), `PROPPATCH changed the record: ${patched.slice(0, 300)}`)
assert.equal((await metadata(alice, path)).record?.record.id, id)
row('PROPPATCH of the key by the owner', `refused (${patch.status}${/HTTP\/1\.1 (\d+)/.exec(patched)?.[1] ? `, ${/HTTP\/1\.1 (\d+)/.exec(patched)[1]}` : ''}); the record is unchanged`)

const move = async (from, to) => {
  const response = await alice.dav(from, 'MOVE', undefined, { Destination: `${base}/remote.php/dav/files/${encodeURIComponent(alice.name)}/${to.split('/').map(encodeURIComponent).join('/')}` })
  assert.ok([201, 204].includes(response.status), `MOVE ${from}: ${response.status}`)
}
const copy = async (from, to) => {
  const response = await alice.dav(from, 'COPY', undefined, { Destination: `${base}/remote.php/dav/files/${encodeURIComponent(alice.name)}/${to.split('/').map(encodeURIComponent).join('/')}` })
  assert.ok([201, 204].includes(response.status), `COPY ${from}: ${response.status}`)
}
await move(path, `${folder}/Summary renamed.md`)
path = `${folder}/Summary renamed.md`
assert.equal((await metadata(alice, path)).record?.record.id, id)
row('Renamed', 'metadata kept')
assert.equal((await alice.dav(`${folder}/Archive`, 'MKCOL')).status, 201)
await move(path, `${folder}/Archive/Summary renamed.md`)
path = `${folder}/Archive/Summary renamed.md`
const moved = await metadata(alice, path)
assert.equal(moved.record?.record.id, id)
assert.equal(moved.fileId, created.fileId)
row('Moved into a folder', 'metadata kept (the same file id)')
await copy(path, `${folder}/Summary copy.md`)
const copied = await metadata(alice, `${folder}/Summary copy.md`)
assert.notEqual(copied.fileId, created.fileId)
assert.equal(copied.record?.record.id, id, 'the copy listener put the record on the copy')
assert.equal(frontMatterMark(await text(alice, `${folder}/Summary copy.md`))?.recordId, id)
row('Copied', `the copy (file ${copied.fileId}) has the record: the app's NodeCopiedEvent listener; front matter in its bytes`)
await copy(`${folder}/Archive`, `${folder}/Archive copy`)
const folderCopy = await metadata(alice, `${folder}/Archive copy/Summary renamed.md`)
assert.notEqual(folderCopy.fileId, created.fileId)
assert.equal(folderCopy.record?.record.id, id)
row('Folder copied', 'the file in the copied folder has the record')
const own = await alice.dav(`${folder}/Own notes.md`, 'PUT', '# Own notes\n')
assert.equal(own.status, 201)
await copy(`${folder}/Own notes.md`, `${folder}/Own notes copy.md`)
assert.equal((await metadata(alice, `${folder}/Own notes copy.md`)).record, undefined)
row('A person\'s own file copied', 'no record appears on its copy')

const edited = (await text(alice, path)).replace('# Summary', '# Summary\n\nAdded by a person over WebDAV.')
assert.equal((await alice.dav(path, 'PUT', edited)).status, 204)
assert.equal((await metadata(alice, path)).record?.record.id, id)
assert.equal(frontMatterMark(await text(alice, path))?.recordId, id)
row('New version over WebDAV', 'metadata and front matter kept')

const shareWithBob = async target => alice.ocs('/apps/files_sharing/api/v1/shares', 'POST', new URLSearchParams({ path: `/${target}`, shareType: '0', shareWith: 'pilot-bob', permissions: '1' }))
const folderShare = await shareWithBob(`${folder}/Archive`)
const inShared = await metadata(bob, `${folderShare.file_target.replace(/^\//, '')}/Summary renamed.md`)
assert.equal(inShared.fileId, moved.fileId)
assert.equal(inShared.record?.record.id, id)
await alice.ocs(`/apps/files_sharing/api/v1/shares/${folderShare.id}`, 'DELETE')
row('Folder shared with pilot-bob', 'he sees the record on the note over WebDAV')
// A file shared on its own is the root of the recipient's share mount, whose WebDAV entry Nextcloud gives no
// metadata: the record is the same file's (server side, and for the owner), only his PROPFIND does not list it.
const fileShare = await shareWithBob(path)
const sharedFile = await metadata(bob, fileShare.file_target.replace(/^\//, ''))
assert.equal(sharedFile.fileId, moved.fileId)
assert.equal((await metadata(alice, path)).record?.record.id, id)
await alice.ocs(`/apps/files_sharing/api/v1/shares/${fileShare.id}`, 'DELETE')
row('File shared with pilot-bob', `the same file (id ${sharedFile.fileId}) keeps the record; his WebDAV ${sharedFile.record ? 'lists' : 'does not list'} it on a single-file share`)

const replyId = replyMeta.fileId
assert.equal((await alice.dav(`${folder}/Reply.txt`, 'DELETE')).status, 204)
const trash = await alice.request(`/remote.php/dav/trashbin/${encodeURIComponent(alice.name)}/trash`, { method: 'PROPFIND', headers: { Depth: '1', 'Content-Type': 'text/xml' }, body: '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns"><d:prop><nc:trashbin-filename/><oc:fileid/></d:prop></d:propfind>' })
const entries = (await trash.text()).split('<d:response>').slice(1)
  .map(entry => [/<d:href>([^<]+)<\/d:href>/.exec(entry)?.[1], Number(/<oc:fileid>(\d+)<\/oc:fileid>/.exec(entry)?.[1])])
const trashed = entries.find(([, fileId]) => fileId === replyId)
assert.ok(trashed?.[0], 'Reply.txt is in the trash')
const restore = await alice.request(trashed[0], { method: 'MOVE', headers: { Destination: `${base}/remote.php/dav/trashbin/${encodeURIComponent(alice.name)}/restore/Reply.txt` } })
assert.ok([201, 204].includes(restore.status), `restore: ${restore.status}`)
assert.equal((await metadata(alice, `${folder}/Reply.txt`)).record?.record.id, reply.record_id)
row('Reply.txt deleted and restored from the trash', 'metadata kept')

if (real) {
  // The copy made after the replacement and before the WebDAV edit: its record is the replacement's (`id`).
  const note = await text(alice, `${folder}/Summary copy.md`)
  assert.equal(note, withFrontMatter(rewritten, id))
  const verify = async body => {
    const response = await fetch(`${real}/v1/provenance/verify`, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` }, body: JSON.stringify(body) })
    const result = await response.json()
    assert.equal(response.status, 200, JSON.stringify(result).slice(0, 300))
    return result
  }
  const describe = result => `${result.verdict}; ${(result.findings ?? []).map(f => `${f.layer}/${f.method}: ${f.found ? 'found' : 'not found'}${f.record_id ? ` (record ${f.record_id === id ? 'the note\'s' : f.record_id})` : ''}`).join('; ')}`
  // Proof the record exists on that server: a text naming its id is found as a reference to it.
  const reference = await verify({ text: `See record ${id}.` })
  assert.ok(reference.findings?.some(f => f.found && f.record_id === id), `the server does not know record ${id}: ${describe(reference)}`)
  row(`Record ${id} on ${real}`, describe(reference))
  // What its detector makes of the note is the server's: reported, not asserted.
  for (const [what, body] of [
    ['the note as Nextcloud stores it', { text: note }],
    ['the approved text exactly as signed', { text: rewritten }],
    ['the words people read and copy', { text: markdownText(note) }],
    ['the note as a Markdown file', { file: Buffer.from(note).toString('base64'), media_type: 'text/markdown' }],
  ]) row(`Detector on ${what}`, describe(await verify(body)))
}

console.log(JSON.stringify({ nextcloud: version, folder, summary: { fileId: created.fileId, record: id, path }, reply: { fileId: replyId, record: reply.record_id }, copy: copied.fileId, checks: metrics.checks }))
await host.dispose()
