// Shared localhost-only integration client. Credentials are disposable dev fixtures.
import assert from 'node:assert/strict'

export const base = process.env.NEXTCLOUD_URL || 'http://localhost:8090'
assert.match(base, /^http:\/\/(localhost|127\.0\.0\.1):\d+$/, 'Fixture operations are restricted to the local dev instances.')
export const metrics = { checks: 0 }
export class User {
  cookies = new Map()
  token = ''
  binding = ''
  constructor(name, password = 'local-pilot-user') { this.name = name; this.password = password }
  async request(path, options = {}) {
    assert.ok(path.startsWith('/') && !path.startsWith('//'))
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
  async api(path, body, expected = 200, signal) {
    if(path.endsWith('/commit')) body = {...body,createProtocol:1}
    const response = await this.request('/index.php/apps/fadenstack_agent'+path, {method:'POST',signal,headers:{'Content-Type':'application/json','X-Fadenstack-Session':this.binding},body:JSON.stringify(body)})
    const data = await response.json()
    assert.equal(response.status,expected, `${path}: ${JSON.stringify(data)}`)
    metrics.checks++
    if(response.status === 200 && data.kind === 'dav-create') {
      assert.ok(data.dav_path.startsWith('/files/'+encodeURIComponent(this.name)+'/'))
      const [parent] = await this.api('/search/resolve',{resourceIds:[data.parent_resource_id]},200,signal)
      assert.equal(parent.path,data.parent_path)
      assert.equal(parent.etag,data.parent_etag)
      assert.ok(parent.permissions & 4)
      const put = await this.request('/remote.php/dav'+data.dav_path,{method:'PUT',body:data.content,signal,headers:{'Content-Type':'text/plain; charset=utf-8','If-None-Match':'*','X-OC-CTime':String(Math.floor(Date.now()/1000))}})
      assert.equal(put.status,201,await put.clone().text())
      return this.api('/creations/finish',{receiptId:data.receiptId},200,signal)
    }
    return data
  }
  async dav(path, method, body, headers = {}) {
    return this.request('/remote.php/dav/files/'+encodeURIComponent(this.name)+'/'+path.split('/').map(encodeURIComponent).join('/'), {method,body,headers:{'Content-Type':'text/plain',...(method === 'PUT' ? {'X-OC-CTime':String(Math.floor(Date.now()/1000))} : {}),...headers}})
  }
  async id(path) {
    const response = await this.dav(path,'PROPFIND','<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',{'Content-Type':'text/xml',Depth:'0'})
    assert.equal(response.status,207)
    return Number((await response.text()).match(/<oc:fileid>(\d+)<\/oc:fileid>/)[1])
  }
  async ocs(path, method = 'GET', body) {
    const response = await this.request('/ocs/v2.php'+path+(path.includes('?')?'&':'?')+'format=json',{method,body,headers:{'OCS-APIRequest':'true','Content-Type':'application/x-www-form-urlencoded'}})
    assert.equal(response.status,200,await response.clone().text())
    const data = await response.json()
    assert.ok([100,200].includes(data.ocs.meta.statuscode),JSON.stringify(data.ocs.meta))
    return data.ocs.data
  }
  files() {
    return {
      identity: async () => (await this.request('/index.php/apps/fadenstack_agent/config')).json(),
      assertIdentity: async () => { const config = await (await this.request('/index.php/apps/fadenstack_agent/config')).json(); assert.deepEqual(config,this.config) },
      select: (fileId,signal) => this.api('/context',{fileId},200,signal),
      read: (contextId,signal) => this.api('/context/read',{contextId},200,signal),
      list: (contextId,signal) => this.api('/context/list',{contextId},200,signal),
      propose: (contextId,args,signal) => this.api('/proposals',{contextId,...args},200,signal),
      approve: (id,signal) => this.api(`/proposals/${id}/approve`,{},200,signal),
      commit: async (id,signal,mark) => { this.committed = await this.api(`/proposals/${id}/commit`,{...(mark ?? {})},200,signal); return this.committed },
      pdf: async (contextId,signal) => {
        const response = await this.request('/index.php/apps/fadenstack_agent/context/content',{method:'POST',signal,headers:{'Content-Type':'application/json','X-Fadenstack-Session':this.binding},body:JSON.stringify({contextId})})
        assert.equal(response.status,200)
        return new Uint8Array(await response.arrayBuffer())
      },
      searchTransport: {
        plan: (filters,signal) => this.api('/search/plan',{filters},200,signal),
        resolve: (resourceIds,scope,signal,content = false) => this.api('/search/resolve',{resourceIds,scope,content},200,signal),
        capabilities: signal => this.api('/search/capabilities',{},200,signal),
        content: (query,limit,page,signal) => this.api('/search/content',{query,limit,page},200,signal),
        dav: async (xml,signal) => {
          const response = await this.request('/remote.php/dav/',{method:'SEARCH',body:xml,signal,headers:{'Content-Type':'text/xml'}})
          assert.equal(response.status,207,await response.clone().text())
          return response.text()
        },
      },
    }
  }
}
