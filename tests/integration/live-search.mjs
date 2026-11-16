// Real SDK + supplied Fadenstack server + real Nextcloud. No simulated model.
import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import { createInterface } from 'node:readline'
import { JSDOM } from 'jsdom'
import { ApprovalPart, ToolCallPart } from '@fadenstack/ui'
import { NextcloudAgent } from '../../src/agent/createAgent.ts'
import { User } from './client.mjs'
import { seedSearch } from '../../scripts/seed-search.mjs'

globalThis.DOMParser = new JSDOM('').window.DOMParser
const user = new User(process.env.NEXTCLOUD_USER || 'pilot-admin',process.env.NEXTCLOUD_PASSWORD || 'local-pilot-only')
await user.login()
assert.ok(process.env.FADENSTACK_SERVER, 'Set FADENSTACK_SERVER to the Fadenstack origin under test.')
assert.equal(user.config.serverUrl,process.env.FADENSTACK_SERVER)
assert.equal(user.config.agent,process.env.FADENSTACK_AGENT || 'nextcloud-agent')
const fixture = await seedSearch(user)
let credentials
if(process.env.FADENSTACK_USERNAME && process.env.FADENSTACK_PASSWORD) {
  credentials = {email:process.env.FADENSTACK_USERNAME,password:process.env.FADENSTACK_PASSWORD}
  delete process.env.FADENSTACK_PASSWORD
} else {
  console.log('Ready for live credentials as one JSON line on stdin (email, password).')
  const input = createInterface({input:process.stdin,terminal:false})
  credentials = JSON.parse(await new Promise(resolve=>input.once('line',resolve)))
  input.close()
}
const files = user.files()
const host = new NextcloudAgent(user.config,files)
const approved = new Set(), serverApproved = new Set()
const filename = `Discovery Summary ${new Date().toISOString().replaceAll(/[:.]/g,'-')}.md`
let writing = false, failure
const approve = files.approve.bind(files), commit = files.commit.bind(files)
files.approve = async (id,signal) => {await approve(id,signal); serverApproved.add(id)}
files.commit = async (id,signal) => {assert.ok(serverApproved.has(id),'An individually approved server proposal is required'); return commit(id,signal)}
function pending() {
  const failed = host.chat.turns.flatMap(turn=>[...turn.parts]).find(part=>part instanceof ToolCallPart && part.name==='nc_create_text_file' && part.state==='failed')
  if(failed && !failure) {failed.toggle();failure=new Error(failed.resultText || 'Native creation failed');host.stop();return}
  for(const part of host.chat.turns.flatMap(turn=>[...turn.parts])) {
    if(!(part instanceof ApprovalPart) || !part.isPending || approved.has(part.request.callId)) continue
    approved.add(part.request.callId)
    void (async()=>{
      if(part.toolName.includes('text_file')) {
        assert.ok(writing,'No write authorized during discovery/passage turns')
        assert.equal(part.toolName,'nc_create_text_file')
        assert.equal(part.request.arguments.filename,filename)
        assert.equal(part.request.arguments.target,`/${fixture.folder}/${filename}`)
        assert.match(String(part.request.arguments.content),/30\s+days/i)
        assert.ok(String(part.request.arguments.content).includes(`/f/${fixture.latestId}`),'The proposed summary cites the latest contract')
        assert.equal(user.committed,undefined,'No commit before approval')
      }
      console.log(`Allow once: ${part.toolName}${part.request.arguments.target ? ' → '+part.request.arguments.target : ''}`)
      await host.approve(part)
    })().catch(error=>{failure=error;part.deny();host.stop()})
  }
}
const timer = setInterval(pending,100)
async function turn(message) {
  const started = performance.now()
  const timeout = setTimeout(()=>host.stop(),Number(process.env.FADENSTACK_TURN_TIMEOUT_MS || 600000))
  try {await host.send(message)} finally {clearTimeout(timeout)}
  if(failure) throw failure
  const result = host.chat.turns.at(-1)
  assert.equal(result.state,'completed',result.errorMessage || result.state)
  console.log(`Completed live turn in ${Math.round(performance.now()-started)}ms.`)
  return result
}
try {
  await host.signIn(credentials.email,credentials.password)
  credentials.password = ''
  assert.ok(host.agent.tools.some(tool=>tool.name==='nc_search_files'),'Published agent permits the discovered search tools')
  console.log(`Connected to real published ${user.config.agent} v${host.agent.profile.version}.`)
  const discovery = await turn('Find the latest supplier contract. Use filename discovery sorted by most recently modified, and include its clickable Nextcloud source link.')
  const calls = discovery.parts.filter(part=>part instanceof ToolCallPart)
  assert.ok(calls.some(part=>part.name==='nc_search_files' && part.state==='succeeded'))
  assert.ok(discovery.answerText.includes(`/f/${fixture.latestId}`))
  const records = calls.map(part=>host.toolResult(part.callId)).filter(Boolean)
  assert.ok(records.some(record=>record.results?.[0]?.resource_id===`nextcloud:files:${fixture.latestId}`),'Newest contract is first in a real search result')
  const answer = await turn('What is the termination notice period in that latest contract? Retrieve the document or find its termination passage. Quote the supporting passage and include its Nextcloud source link; do not infer from the filename.')
  assert.match(answer.answerText,/30\s+days/i)
  assert.ok(answer.answerText.includes(`/f/${fixture.latestId}`))
  assert.ok(answer.parts.some(part=>part instanceof ToolCallPart && ['nc_search_in_file','nc_read_file'].includes(part.name) && part.state==='succeeded'))
  host.offerSelection(await host.selection.select(fixture.folderId))
  writing = true
  await turn(`Create a Markdown summary named "${filename}" in the current included folder. Include the latest contract's termination notice period, supporting passage and source link. Use nc_create_text_file and wait for individual approval.`)
  assert.ok(user.committed?.file?.id)
  const context = await files.select(user.committed.file.id)
  const saved = await files.read(context.id)
  assert.match(saved.text,/30\s+days/i)
  assert.ok(saved.text.includes(`/f/${fixture.latestId}`))
  await mkdir('build/live-search',{recursive:true})
  await writeFile('build/live-search/summary.md',saved.text)
  await writeFile('build/live-search/result.json',JSON.stringify({server:user.config.serverUrl,agent:user.config.agent,version:host.agent.profile.version,fixture,created:user.committed.file,approvedCalls:approved.size,verifiedAt:new Date().toISOString()},null,2)+'\n')
  console.log(`Real search → passage → approved summary passed: ${user.committed.file.url}`)
} finally {
  clearInterval(timer)
  await mkdir('build/live-search',{recursive:true})
  await writeFile('build/live-search/turns.json',JSON.stringify(host.chat.turns.map(turn=>({state:turn.state,answer:turn.answerText,error:turn.errorMessage,tools:turn.parts.filter(part=>part instanceof ToolCallPart).map(part=>({name:part.name,state:part.state,result:host.toolResult(part.callId)}))})),null,2)+'\n')
  await host.dispose()
}
