// Real Fadenstack + real loopback Nextcloud acceptance. No simulated gateway.
// Node 24: node --experimental-transform-types tests/integration/live.mjs
// Provide Fadenstack credentials as one JSON line on stdin, never in files.
import assert from 'node:assert/strict'
import { createInterface } from 'node:readline'
import { mkdir, writeFile } from 'node:fs/promises'
import { ApprovalPart } from '@fadenstack/ui'
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs'
import { User } from "./client.mjs"
import { extractPdfWith } from "../../src/integrations/nextcloud/pdf-core.ts"
import { NextcloudAgent } from '../../src/agent/createAgent.ts'

const user = process.env.NEXTCLOUD_USER || "pilot-admin"
const password = process.env.NEXTCLOUD_PASSWORD || "local-pilot-only"
const local = new User(user, password)
await local.login()
const base = local.config.nextcloudUrl || process.env.NEXTCLOUD_URL || "http://localhost:8090"
const config = local.config
assert.equal(config.serverUrl, process.env.FADENSTACK_SERVER || "https://10.250.10.61")
assert.equal(config.agent, process.env.FADENSTACK_AGENT || "nextcloud-agent")
const request = local.request.bind(local)
const files = local.files()
let committed
const commit = files.commit
files.commit = async (id, signal) => { committed = await commit(id, signal); return committed }
const fileId = Number(process.env.NEXTCLOUD_FILE_ID || 73)
const turnTimeout = Number(process.env.FADENSTACK_TURN_TIMEOUT_MS || 600000)
const denialOnly = process.env.FADENSTACK_SCENARIO === "deny"
class NodePilotAgent extends NextcloudAgent {
  constructor(...args) {
    super(...args)
    this.discovery.extract = async (context, signal) => context.file.mime === "application/pdf"
      ? extractPdfWith(getDocument, await files.pdf(context.id, signal), signal)
      : files.read(context.id, signal)
  }
}
let credentials
if (process.env.FADENSTACK_USERNAME && process.env.FADENSTACK_PASSWORD) {
  credentials = { email: process.env.FADENSTACK_USERNAME, password: process.env.FADENSTACK_PASSWORD }
  delete process.env.FADENSTACK_PASSWORD
} else {
  console.log('Ready for live Fadenstack credentials as one JSON line on stdin (email, password).')
  const input = createInterface({ input: process.stdin, terminal: false })
  credentials = JSON.parse(await new Promise(resolve => input.once('line', resolve)))
  input.close()
}
const host = new NodePilotAgent(config, files)
const approved = new Set()
const filename = `live-nextcloud-summary-${new Date().toISOString().replaceAll(/[:.]/g, '-')}.md`
let deniedCalls = 0
let activeWrite = false
let approvalError
function approvePending() {
  for (const part of host.chat.turns.flatMap(turn => [...turn.parts])) {
    if (!(part instanceof ApprovalPart) || !part.isPending || approved.has(part.request.callId)) continue
    approved.add(part.request.callId)
    void (async () => {
      if (part.toolName.includes('text_file')) {
        if (denialOnly) {
          assert.equal(part.toolName, 'nc_create_text_file')
          assert.equal(part.request.arguments.filename, filename)
          deniedCalls++
          console.log(`Deny once: ${part.toolName} → ${part.request.arguments.target}`)
          part.deny()
          host.stop()
          return
        }
        assert.ok(activeWrite, 'No write was requested during the summary-only turn')
        assert.equal(part.toolName, 'nc_create_text_file')
        assert.equal(part.request.arguments.filename, filename)
        assert.equal(part.request.arguments.before, null)
        assert.ok(String(part.request.arguments.content).includes(`/f/${fileId}`), 'Proposed Markdown links to its source')
        assert.ok(String(part.request.arguments.content).length > 300)
      }
      console.log(`Allow once: ${part.toolName}${part.request.arguments.target ? ` → ${part.request.arguments.target}` : ''}`)
      await host.approve(part)
    })().catch(error => { approvalError = error; part.deny(); host.stop() })
  }
}
// Parts notify their own view model, rather than the parent chat. Scan pending
// cards as the Vue turn/part renderers do, so a waiting approval is not missed.
const approvalTimer = setInterval(approvePending, 100)
try {
  await host.signIn(credentials.email, credentials.password)
  credentials.password = ''
  console.log(`Connected to real ${config.agent}, published v${host.agent.profile.version}.`)
  console.log(`Governed tools: ${host.agent.tools.map(tool => tool.name).join(', ') || '(none)'}`)
  const selected = await host.selection.select(fileId)
  if (!denialOnly) assert.equal(selected.file.mime, 'application/pdf')
  host.offerSelection(selected)
  if (denialOnly) {
    const timeout = setTimeout(() => host.stop(), turnTimeout)
    try { await host.send(`Call nc_create_text_file once with filename ${filename} and content "# Denial probe\\nThis must not be saved if denied." Request individual approval.`) }
    finally { clearTimeout(timeout) }
    if (approvalError) throw approvalError
    assert.ok(deniedCalls > 0, 'Real model requested a write that was denied')
    assert.equal(committed, undefined, 'No commit was made')
    const deniedFile = await fetch(`${base}/remote.php/dav/files/${encodeURIComponent(user)}/${encodeURIComponent(filename)}`, {
      headers: { Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}` },
    })
    assert.equal(deniedFile.status, 404, 'Denied file does not exist on real Nextcloud')
    await mkdir('build/live', { recursive: true })
    await writeFile('build/live/denial.json', JSON.stringify({ server: config.serverUrl, filename, deniedCalls, exists: false, verifiedAt: new Date().toISOString() }, null, 2) + '\n')
    console.log('Live denial passed: no file was created.')
  } else {
  const timeout = setTimeout(() => host.stop(), turnTimeout)
  try { await host.send('Summarize the attached PDF in five useful bullet points. Ground the summary in the document. Include a Markdown link to its supplied Nextcloud source URL.') }
  finally { clearTimeout(timeout) }
  const summary = host.chat.turns.at(-1)
  assert.equal(summary.state, 'completed', summary.errorMessage)
  assert.ok(summary.answerText.length > 300, 'Real model produced a substantive answer')
  assert.ok(summary.answerText.includes(`/f/${fileId}`), 'Real answer includes the source link')
  await mkdir('build/live', { recursive: true })
  await writeFile('build/live/summary.md', summary.answerText + '\n')
  console.log('Real PDF summary and source link verified.')
  activeWrite = true
  const writeTimeout = setTimeout(() => host.stop(), turnTimeout)
  try { await host.send(`Create a Markdown file named ${filename} in the selected PDF's current folder, using nc_create_text_file. Save the five-point summary and its source link. Do not merely paste Markdown in the chat. Wait for individual approval.`) }
  finally { clearTimeout(writeTimeout) }
  if (approvalError) throw approvalError
  const writeTurn = host.chat.turns.at(-1)
  assert.equal(writeTurn.state, 'completed', writeTurn.errorMessage || `Write turn ${writeTurn.state}`)
  assert.ok(committed?.file?.url, 'Approved write returned a real file URL')
  const savedContext = await files.select(committed.file.id)
  const saved = await files.read(savedContext.id)
  assert.ok(saved.text.length > 300)
  assert.ok(saved.text.includes(`/f/${fileId}`))
  const link = await request(new URL(committed.file.url).pathname)
  assert.ok([302, 303].includes(link.status), 'Real result short link redirects to Files')
  let destination = new URL(link.headers.get('location'), base)
  let landing
  for (let hop = 0; hop < 5; hop++) {
    assert.equal(destination.origin, new URL(base).origin)
    landing = await request(destination.pathname + destination.search)
    if (![301, 302, 303, 307, 308].includes(landing.status)) break
    destination = new URL(landing.headers.get('location'), base)
  }
  assert.equal(landing.status, 200, 'Result short link resolves to authenticated Files')
  assert.ok((await landing.text()).includes('fadenstack_agent-assistant'), 'Files shell loads the installed assistant; file contents are verified through the API above')
  await mkdir('build/live', { recursive: true })
  await writeFile('build/live/summary.md', summary.answerText + '\n')
  await writeFile('build/live/created-summary.md', saved.text)
  await writeFile('build/live/result.json', JSON.stringify({ server: config.serverUrl, agent: config.agent, version: host.agent.profile.version, source: selected.file.url, created: committed.file, approvedCalls: approved.size, verifiedAt: new Date().toISOString() }, null, 2) + '\n')
  console.log(`Live acceptance passed. Saved ${committed.file.name}: ${committed.file.url}`)
  }
} finally {
  await mkdir('build/live', { recursive: true })
  if (committed) await writeFile('build/live/committed.json', JSON.stringify(committed, null, 2) + '\n')
  await writeFile('build/live/turns.json', JSON.stringify(host.chat.turns.map(turn => ({ state: turn.state, answer: turn.answerText, error: turn.errorMessage,
    parts: turn.parts.map(part => ({ kind: part.constructor.name, title: part.title, message: part.message })) })), null, 2) + '\n')
  clearInterval(approvalTimer); await host.dispose()
}
