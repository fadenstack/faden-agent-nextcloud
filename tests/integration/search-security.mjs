import assert from 'node:assert/strict'
import { JSDOM } from 'jsdom'
import { User, base, metrics } from './client.mjs'
import { NextcloudSearchService } from '../../src/integrations/nextcloud/search.ts'

globalThis.DOMParser = new JSDOM('').window.DOMParser
const alice = new User('pilot-alice'), bob = new User('pilot-bob')
await alice.login(); await bob.login()
const folder = `search-security-${Date.now()}`
assert.equal((await alice.dav(folder,'MKCOL')).status,201)
const scope = `nextcloud:files:${await alice.id(folder)}`
const file = folder+'/Shared supplier contract.md'
assert.equal((await alice.dav(file,'PUT','# Synthetic contract\nTermination notice: 30 days.')).status,201)
const id = await alice.id(file)
const signal = new AbortController().signal
const a = new NextcloudSearchService(alice.files()), b = new NextcloudSearchService(bob.files())
const found = await a.search({query:'Shared supplier',scope},signal)
assert.equal(found.results[0].resource_id,`nextcloud:files:${id}`)
assert.equal((await b.search({query:folder},signal)).results.length,0)
assert.deepEqual(await bob.api('/search/resolve',{resourceIds:[`nextcloud:files:${id}`]}),[])
const share = await alice.ocs('/apps/files_sharing/api/v1/shares','POST',new URLSearchParams({path:'/'+file,shareType:'0',shareWith:'pilot-bob',permissions:'1'}))
const shared = await b.search({query:'Shared supplier contract'},signal)
assert.ok(shared.results.some(result=>result.resource_id===`nextcloud:files:${id}`))
const passage = await b.inFile(`nextcloud:files:${id}`,'termination','exact_phrase',10,signal)
assert.ok(passage.matches[0].excerpt.includes('30 days'))
const oldContext = b.cache.get(`nextcloud:files:${id}`).context
await alice.ocs('/apps/files_sharing/api/v1/shares/'+share.id,'DELETE')
await assert.rejects(b.inFile(`nextcloud:files:${id}`,'termination','exact_phrase',10,signal),/revoked/)
assert.equal(b.cache.size,0)
assert.equal((await b.search({query:'Shared supplier contract'},signal)).results.length,0)
await bob.api('/context/read',{contextId:oldContext.id},404)

for(const name of ['100% real_plan.md','100X realXplan.md','Überblick & <Plan>.md']) assert.equal((await alice.dav(folder+'/'+name,'PUT','# Search fixture')).status,201)
assert.equal((await a.search({query:'100%',scope},signal)).results.length,1)
assert.equal((await a.search({query:'real_',scope},signal)).results.length,1)
const favorite = '<d:propertyupdate xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:set><d:prop><oc:favorite>1</oc:favorite></d:prop></d:set></d:propertyupdate>'
assert.equal((await alice.dav(file,'PROPPATCH',favorite,{'Content-Type':'text/xml'})).status,207)
assert.equal((await a.search({scope,favorites_only:true},signal)).results[0].resource_id,`nextcloud:files:${id}`)
const serverVersion = await (await alice.request('/status.php')).json()
if (serverVersion.version.split('.')[0] >= 35) assert.ok((await a.search({scope,created_after:'2026-01-01T00:00:00.000Z'},signal)).results.length >= 1)
else await alice.api('/search/plan',{filters:{scope,created_after:'2026-01-01T00:00:00.000Z'}},422)

const first = await a.search({scope,sort_by:'name',sort_direction:'asc',limit:2},signal)
assert.ok(first.next_cursor)
const second = await a.search({scope,sort_by:'name',sort_direction:'asc',limit:2,cursor:first.next_cursor},signal)
assert.ok(second.results.length > 0)
assert.ok(second.results.every(row=>!first.results.some(old=>old.resource_id===row.resource_id)))
await alice.api('/search/plan',{filters:{scope,sort_by:'name',sort_direction:'asc',limit:2,cursor:first.next_cursor+'x'}},400)
await alice.api('/search/plan',{filters:{scope,sort_by:'name',sort_direction:'asc',limit:3,cursor:first.next_cursor}},400)
const anotherSession = new User('pilot-alice'); await anotherSession.login()
await anotherSession.api('/search/plan',{filters:{scope,sort_by:'name',sort_direction:'asc',limit:2,cursor:first.next_cursor}},400)

const c = await alice.files().select(Number(scope.split(':').at(-1)))
const create = await alice.files().propose(c.id,{operation:'create',filename:'Approved nested summary.md',content:'# Approved nested summary'})
await alice.files().approve(create.id)
const legacy = await alice.request(`/index.php/apps/fadenstack_agent/proposals/${create.id}/commit`,{method:'POST',headers:{'Content-Type':'application/json','X-Fadenstack-Session':alice.binding},body:'{}'})
assert.equal(legacy.status,409)
assert.match((await legacy.json()).message,/Reload.*No file was created/)
assert.equal((await alice.dav(folder+'/Approved nested summary.md','GET')).status,404)
metrics.checks++
const written = await alice.files().commit(create.id)
assert.equal(written.file.path,'/'+folder+'/Approved nested summary.md')
assert.equal(await (await alice.dav(folder+'/Approved nested summary.md','GET')).text(),'# Approved nested summary')
await alice.api('/creations/finish',{receiptId:create.id},410)

// Native If-None-Match protects the interval after app approval too.
const current = await alice.files().select(Number(scope.split(':').at(-1)))
const race = await alice.files().propose(current.id,{operation:'create',filename:'External author wins.md',content:'# Must not overwrite'})
await alice.files().approve(race.id)
const planResponse = await alice.request(`/index.php/apps/fadenstack_agent/proposals/${race.id}/commit`,{method:'POST',headers:{'Content-Type':'application/json','X-Fadenstack-Session':alice.binding},body:'{"createProtocol":1}'})
assert.equal(planResponse.status,200)
const plan = await planResponse.json()
assert.equal(plan.kind,'dav-create')
await bob.api('/creations/finish',{receiptId:plan.receiptId},410)
assert.equal((await alice.dav(folder+'/External author wins.md','PUT','# External author content')).status,201)
const prevented = await alice.request('/remote.php/dav'+plan.dav_path,{method:'PUT',body:plan.content,headers:{'If-None-Match':'*','Content-Type':'text/plain'}})
assert.equal(prevented.status,412)
assert.equal(await (await alice.dav(folder+'/External author wins.md','GET')).text(),'# External author content')
await alice.api('/creations/finish',{receiptId:plan.receiptId},409)
console.log(`Search/share/cursor/nested-write security assertions passed on ${base}; ${metrics.checks} authenticated API checks. Fixtures retained: ${folder}`)
