import assert from 'node:assert/strict'
import { JSDOM } from 'jsdom'
import { NextcloudSearchService } from '../../src/integrations/nextcloud/search.ts'

const base = process.env.NEXTCLOUD_URL || 'http://localhost:8090'
assert.match(base, /^http:\/\/(localhost|127\.0\.0\.1):\d+$/)
globalThis.DOMParser = new JSDOM('').window.DOMParser
let checks = 0
class User {
  cookies = new Map()
  token = ''
  binding = ''
  constructor(name, password = 'local-pilot-user') { this.name = name; this.password = password }
  async request(path, options = {}) {
    const response = await fetch(base + path, { ...options, redirect: 'manual', headers: {
      Origin: base, Cookie: [...this.cookies].map(([k,v]) => `${k}=${v}`).join('; '), requesttoken: this.token, ...options.headers,
    } })
    for (const cookie of response.headers.getSetCookie()) { const pair = cookie.split(';')[0]; const i = pair.indexOf('='); this.cookies.set(pair.slice(0,i), pair.slice(i+1)) }
    return response
  }
  async login() {
    this.token = (await (await this.request('/index.php/login')).text()).match(/data-requesttoken="([^"]+)"/)[1]
    const login = await this.request('/index.php/login', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({user:this.name,password:this.password,requesttoken:this.token}) })
    assert.equal(login.status,303)
    const page = await (await this.request('/index.php/apps/files/files')).text()
    this.token = page.match(/data-requesttoken="([^"]+)"/)[1]
    this.config = await (await this.request('/index.php/apps/fadenstack_agent/config')).json()
    this.binding = this.config.binding
  }
  async api(path, body, expected = 200) {
    const response = await this.request('/index.php/apps/fadenstack_agent'+path, {method:'POST',headers:{'Content-Type':'application/json','X-Fadenstack-Session':this.binding},body:JSON.stringify(body)})
    const data = await response.json()
    assert.equal(response.status,expected, `${path}: ${JSON.stringify(data)}`)
    checks++
    return data
  }
  async dav(path, method, body, headers = {}) {
    return this.request('/remote.php/dav/files/'+encodeURIComponent(this.name)+'/'+path.split('/').map(encodeURIComponent).join('/'), {method,body,headers:{'Content-Type':'text/plain',...headers}})
  }
  async id(path) {
    const response = await this.dav(path,'PROPFIND','<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',{'Content-Type':'text/xml',Depth:'0'})
    assert.equal(response.status,207)
    return Number((await response.text()).match(/<oc:fileid>(\d+)<\/oc:fileid>/)[1])
  }
  files() {
    return {
      assertIdentity: async () => { const config = await (await this.request('/index.php/apps/fadenstack_agent/config')).json(); assert.deepEqual(config,this.config) },
      select: (fileId,signal) => this.api('/context',{fileId}),
      read: (contextId,signal) => this.api('/context/read',{contextId}),
      searchTransport: {
        plan: (filters,signal) => this.api('/search/plan',{filters}),
        resolve: (resourceIds,scope,signal) => this.api('/search/resolve',{resourceIds,scope}),
        dav: async (xml,signal) => {
          const response = await this.request('/remote.php/dav/',{method:'SEARCH',body:xml,signal,headers:{'Content-Type':'text/xml'}})
          assert.equal(response.status,207,await response.clone().text())
          return response.text()
        },
      },
    }
  }
}
const alice = new User('pilot-alice'), bob = new User('pilot-bob')
await alice.login(); await bob.login()
const root = `search-${Date.now()}`
for (const folder of [root,root+'/Engineering',root+'/Engineering/Subfolder',root+'/Finance']) assert.equal((await alice.dav(folder,'MKCOL')).status,201)
const fixtures = [
  ['Engineering/supplier contract.md','# Supplier Contract\n\nTermination notice period: 30 days written notice.\nWarranty: 24 months.','2026-10-12T09:30:00Z'],
  ['Engineering/supplier contract old.md','# Older contract\nTermination: 90 days.','2026-09-01T09:30:00Z'],
  ['Engineering/Subfolder/Überblick & <Plan>.md','# Quality assurance\nISO 9001 is mentioned here.','2026-10-10T10:00:00Z'],
  ['Engineering/budget.xlsx','Discovery fixture, not a readable office document.','2026-10-11T10:00:00Z'],
  ['Finance/supplier invoice.md','# Invoice\nFinance only.','2026-10-13T08:00:00Z'],
]
for(const [path,text,date] of fixtures) assert.equal((await alice.dav(root+'/'+path,'PUT',text,{'X-OC-MTime':String(Date.parse(date)/1000)})).status,201)
const folderId = await alice.id(root+'/Engineering')
const fileId = await alice.id(root+'/Engineering/supplier contract.md')
const scope = `nextcloud:files:${folderId}`
const service = new NextcloudSearchService(alice.files())
const search = filters => service.search(filters,new AbortController().signal)
const latest = await search({query:'supplier',scope,sort_by:'modified',sort_direction:'desc'})
assert.deepEqual(latest.results.map(f=>f.name),['supplier contract.md','supplier contract old.md'])
assert.equal(latest.results[0].modified_at,'2026-10-12T09:30:00.000Z')
assert.match(latest.results[0].web_url,/\/f\/\d+$/)
checks++
const exact = await search({query:'supplier contract.md',scope})
assert.equal(exact.results[0].name,'supplier contract.md'); checks++
assert.equal((await search({file_types:['md'],scope,modified_after:'2026-10-01T00:00:00Z',min_size:10,max_size:200})).results.length,2); checks++
assert.equal((await search({file_types:['xlsx'],scope})).results[0].name,'budget.xlsx'); checks++
assert.equal((await search({query:'Überblick & <Plan>',scope})).results.length,1); checks++
assert.equal((await search({query:'nonexistent',scope})).results.length,0); checks++
assert.equal((await search({scope,limit:1})).results.length,1); checks++
const limited = await search({scope,limit:1})
assert.equal(limited.partial,true); checks++
const privateResult = await new NextcloudSearchService(bob.files()).search({query:root},new AbortController().signal)
assert.equal(privateResult.results.length,0)
assert.deepEqual(await bob.api('/search/resolve',{resourceIds:[`nextcloud:files:${fileId}`]}),[])
await bob.api('/context',{fileId},404)
await bob.api('/search/plan',{filters:{scope}},404)
for(const filters of [{scope:'../../alice'},{query:'</d:literal>/foo'},{limit:51},{modified_after:'2026-02-30T00:00:00Z'},{file_types:['../../php']},{min_size:-1},{sort_by:'invented'}]) await alice.api('/search/plan',{filters},400)
await alice.api('/search/resolve',{resourceIds:['https://evil.invalid/file']},400)
console.log(`${checks} native search checks passed on ${base}; ${latest.duration_ms}ms latest-contract query. Fixtures retained: ${root}`)
