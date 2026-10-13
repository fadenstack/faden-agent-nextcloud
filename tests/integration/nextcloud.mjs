import assert from 'node:assert/strict'

const base = process.env.NEXTCLOUD_URL || 'http://localhost:8090'
assert.match(base, /^http:\/\/(localhost|127\.0\.0\.1):\d+$/, 'Integration fixtures only run against an explicit localhost dev instance.')
const pass = 'local-pilot-user'
let checks = 0
class User {
  cookies = new Map()
  token = ''
  binding = ''
  constructor(name) { this.name = name }
  async request(path, options = {}) {
    const res = await fetch(`${base}${path}`, { ...options, redirect: 'manual', headers: { Origin: base, Cookie: [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; '), ...options.headers } })
    for (const cookie of res.headers.getSetCookie()) { const [pair] = cookie.split(';'); const i = pair.indexOf('='); this.cookies.set(pair.slice(0, i), pair.slice(i + 1)) }
    return res
  }
  async login() {
    const page = await (await this.request('/index.php/login')).text()
    this.token = page.match(/data-requesttoken="([^"]+)"/)?.[1]
    assert.ok(this.token, 'Nextcloud login CSRF token')
    const res = await this.request('/index.php/login', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ user: this.name, password: pass, requesttoken: this.token }) })
    assert.equal(res.status, 303, 'Nextcloud login succeeded')
    let landing = await this.request('/index.php/apps/files/')
    for (let i = 0; i < 5 && [301, 302, 303, 307].includes(landing.status); i++) {
      const destination = new URL(landing.headers.get('location'), base)
      assert.equal(destination.origin, new URL(base).origin)
      landing = await this.request(destination.pathname + destination.search)
    }
    const authenticated = await landing.text()
    this.token = authenticated.match(/data-requesttoken="([^"]+)"/)?.[1]
    assert.ok(this.token, `Authenticated CSRF token (login destination ${res.headers.get('location')}; landing status ${landing.status}; title ${authenticated.match(/<title>([^<]+)<\/title>/)?.[1]})`)
    const config = await this.request('/index.php/apps/fadenstack_agent/config')
    assert.equal(config.status, 200, 'App config')
    const identity = await config.json()
    assert.equal(identity.userId, this.name)
    this.binding = identity.binding
    assert.ok(authenticated.includes('fadenstack_agent-assistant'), 'Native drawer assets registered')
  }
  async api(path, body, status = 200, headers = {}) {
    if(path.endsWith('/commit')) body = {...body,createProtocol:1}
    const res = await this.request(`/index.php/apps/fadenstack_agent${path}`, { method: 'POST', headers: { 'Content-Type': 'application/json', requesttoken: this.token, 'X-Fadenstack-Session': this.binding, ...headers }, body: JSON.stringify(body) })
    const data = await res.json()
    assert.equal(res.status, status, `${path}: ${data.message || 'unexpected status'}`)
    checks++
    if(res.status === 200 && data.kind === 'dav-create') {
      assert.ok(data.dav_path.startsWith(`/files/${encodeURIComponent(this.name)}/`))
      const [parent] = await this.api('/search/resolve',{resourceIds:[data.parent_resource_id]})
      assert.equal(parent.path,data.parent_path)
      assert.equal(parent.etag,data.parent_etag)
      const put = await this.request('/remote.php/dav'+data.dav_path,{method:'PUT',body:data.content,headers:{requesttoken:this.token,'Content-Type':'text/plain; charset=utf-8','If-None-Match':'*'}})
      assert.equal(put.status,201,await put.clone().text())
      return this.api('/creations/finish',{receiptId:data.receiptId})
    }
    return data
  }
  async dav(path, method = 'GET', body) {
    return fetch(`${base}/remote.php/dav/files/${this.name}/${path}`, { method, body, headers: { Authorization: `Basic ${Buffer.from(`${this.name}:${pass}`).toString('base64')}`, 'Content-Type': 'text/plain; charset=utf-8' } })
  }
  async id(path) {
    const res = await this.dav(path, 'PROPFIND', '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>')
    assert.equal(res.status, 207)
    return Number((await res.text()).match(/<oc:fileid>(\d+)<\/oc:fileid>/)?.[1])
  }
}
const alice = new User('pilot-alice')
const bob = new User('pilot-bob')
await alice.login()
await bob.login()
const appEntry = await alice.request('/index.php/apps/fadenstack_agent/')
assert.ok([302, 303].includes(appEntry.status), 'Direct app entry redirects to Files')
const appDestination = new URL(appEntry.headers.get('location'), base)
assert.equal(appDestination.origin, new URL(base).origin)
assert.ok(appDestination.pathname.includes('/apps/files'), `App entry destination: ${appDestination.href}`)
assert.equal(appDestination.searchParams.get('fadenstack-agent'), '1', 'Direct entry requests the drawer')
checks++
const name = `integration-${Date.now()}.md`
assert.equal((await alice.dav(name, 'PUT', '# Original\nAlice private document.')).status, 201)
const id = await alice.id(name)
const context = await alice.api('/context', { fileId: id })
assert.equal(context.file.id, id)
assert.equal(context.file.name, name)
assert.ok(!('text' in context), 'Selection exposes metadata only')
const read = await alice.api('/context/read', { contextId: context.id })
assert.equal(read.text, '# Original\nAlice private document.')
assert.equal(read.partial, false)
assert.ok(read.file.url.includes(String(id)), 'Usable source link')
await bob.api('/context', { fileId: id }, 404)
await bob.api('/context/read', { contextId: context.id }, 410)
await alice.api('/context/read', { contextId: context.id }, 401, { 'X-Fadenstack-Session': bob.binding })
const create = await alice.api('/proposals', { contextId: context.id, operation: 'create', filename: `${name}.txt`, content: 'Approved summary' })
await alice.api(`/proposals/${create.id}/commit`, {}, 403)
await bob.api(`/proposals/${create.id}/approve`, {}, 410)
await alice.api(`/proposals/${create.id}/approve`, {})
const created = await alice.api(`/proposals/${create.id}/commit`, {})
assert.equal(created.file.name, `${name}.txt`)
assert.equal(await (await alice.dav(`${name}.txt`)).text(), 'Approved summary')
await alice.api(`/proposals/${create.id}/commit`, {}, 410)
await alice.api('/proposals', { contextId: context.id, operation: 'create', filename: `${name}.txt`, content: 'Overwrite' }, 409)
await alice.api('/proposals', { contextId: context.id, operation: 'create', filename: '../escape.md', content: 'Escape' }, 400)
await alice.api('/proposals', { contextId: context.id, operation: 'create', filename: 'run.php', content: 'Unsafe' }, 400)
const update = await alice.api('/proposals', { contextId: context.id, operation: 'update', expectedEtag: read.file.etag, content: '# Approved replacement' })
assert.equal(update.before, read.text)
await alice.api(`/proposals/${update.id}/approve`, {})
await alice.api(`/proposals/${update.id}/commit`, {})
assert.equal(await (await alice.dav(name)).text(), '# Approved replacement')
const refreshed = await alice.api('/context', { fileId: id })
const latest = await alice.api('/context/read', { contextId: refreshed.id })
const conflict = await alice.api('/proposals', { contextId: refreshed.id, operation: 'update', expectedEtag: latest.file.etag, content: 'Must never overwrite' })
await alice.api(`/proposals/${conflict.id}/approve`, {})
assert.equal((await alice.dav(name, 'PUT', 'Concurrent author edit')).status, 204)
await alice.api(`/proposals/${conflict.id}/commit`, {}, 409)
assert.equal(await (await alice.dav(name)).text(), 'Concurrent author edit')
const folder = await alice.api('/context/list', { contextId: refreshed.id })
assert.ok(folder.files.some(f => f.id === id))
const unsupported = `${name}.bin`
await alice.dav(unsupported, 'PUT', 'Binary placeholder')
const binary = await alice.api('/context', { fileId: await alice.id(unsupported) })
await alice.api('/context/read', { contextId: binary.id }, 415)
const large = `${name}-large.txt`
await alice.dav(large, 'PUT', 'a'.repeat(2097153))
const largeContext = await alice.api('/context', { fileId: await alice.id(large) })
await alice.api('/context/read', { contextId: largeContext.id }, 413)
const bounded = `${name}-bounded.txt`
await alice.dav(bounded, 'PUT', 'a'.repeat(60000))
const boundedContext = await alice.api('/context', { fileId: await alice.id(bounded) })
const partial = await alice.api('/context/read', { contextId: boundedContext.id })
assert.equal(partial.partial, true)
assert.equal(partial.processedCharacters, 50000)
const readOnly = `read-only-${Date.now()}`
assert.equal((await alice.dav(readOnly, 'MKCOL')).status, 201)
assert.equal((await alice.dav(`${readOnly}/shared.md`, 'PUT', 'Readable but never editable by Bob')).status, 201)
const share = await fetch(`${base}/ocs/v2.php/apps/files_sharing/api/v1/shares`, {
  method: 'POST', headers: { Authorization: `Basic ${Buffer.from(`${alice.name}:${pass}`).toString('base64')}`, 'OCS-APIRequest': 'true', 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
  body: new URLSearchParams({ path: `/${readOnly}`, shareType: '0', shareWith: bob.name, permissions: '1' }),
})
assert.equal(share.status, 200, 'Create a read-only development share')
const sharedFolder = await bob.api('/context', { fileId: await alice.id(readOnly) })
await bob.api('/proposals', { contextId: sharedFolder.id, operation: 'create', filename: 'denied.md', content: 'Must not be saved' }, 403)
const sharedFile = await bob.api('/context', { fileId: await alice.id(`${readOnly}/shared.md`) })
const sharedText = await bob.api('/context/read', { contextId: sharedFile.id })
assert.equal(sharedText.text, 'Readable but never editable by Bob')
await bob.api('/proposals', { contextId: sharedFile.id, operation: 'update', expectedEtag: sharedText.file.etag, content: 'Must not be saved' }, 403)
const otherAlice = new User('pilot-alice')
await otherAlice.login()
await otherAlice.api('/context/read', { contextId: context.id }, 410)
const csrf = await alice.request('/index.php/apps/fadenstack_agent/context', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Fadenstack-Session': alice.binding }, body: JSON.stringify({ fileId: id }) })
assert.equal(csrf.status, 412, 'CSRF protection enforced')
checks++
const configDenied = await bob.request('/index.php/apps/fadenstack_agent/config', { method: 'PUT', headers: { 'Content-Type': 'application/json', requesttoken: bob.token }, body: JSON.stringify({ serverUrl: 'https://other.test', agent: 'other', enabled: true }) })
assert.equal(configDenied.status, 403, 'Admin settings protected')
checks++
console.log(`${checks} real Nextcloud HTTP checks passed on ${base}. Test fixtures retained for inspection.`)
