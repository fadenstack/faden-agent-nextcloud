import assert from 'node:assert/strict'

const server = process.env.FADENSTACK_SERVER
if (!server) throw new Error('Set FADENSTACK_SERVER to the Fadenstack origin under test.')
const agent = process.env.FADENSTACK_AGENT || 'nextcloud-agent'
let checks = 0
const health = await fetch(`${server}/healthz`)
assert.equal(health.status, 200, 'HTTPS with certificate and hostname validation')
checks++
for (const origin of ['http://localhost:8090', 'http://localhost:8088', 'http://127.0.0.1:8088']) {
  for (const path of ['/api/auth/jwt/login', '/api/agent-tokens', '/api/agent-tokens/refresh', `/v1/agents/${agent}/profile`, `/v1/agents/${agent}/chat/completions`]) {
    const result = await fetch(`${server}${path}`, { method: 'OPTIONS', headers: {
      Origin: origin, 'Access-Control-Request-Method': 'POST',
      'Access-Control-Request-Headers': 'authorization,content-type,x-faden-tool-mode,x-faden-events,x-faden-conversation-id,x-faden-turn-id',
    } })
    assert.equal(result.status, 204, path)
    assert.equal(result.headers.get('access-control-allow-origin'), origin)
    assert.equal(result.headers.get('access-control-allow-credentials'), null)
    assert.equal(result.headers.get('x-content-type-options'), 'nosniff', 'Inherited security header retained')
    assert.ok(result.headers.get('strict-transport-security'), 'Inherited HSTS header retained')
    checks++
  }
}
for (const [path, origin] of [
  ['/api/agent-tokens', 'https://unapproved.invalid'],
  ['/api/admin/agents', 'http://localhost:8090'],
  ['/api/users/me', 'http://localhost:8090'],
  ['/v1/agents/another-agent/profile', 'http://localhost:8090'],
]) {
  const result = await fetch(`${server}${path}`, { method: 'OPTIONS', headers: { Origin: origin, 'Access-Control-Request-Method': 'POST' } })
  assert.equal(result.headers.get('access-control-allow-origin'), null, `${origin} cannot read ${path}`)
  checks++
}
const unauthenticated = await fetch(`${server}/v1/agents/${agent}/profile`, { headers: { Origin: 'http://localhost:8090' } })
assert.ok([401, 403].includes(unauthenticated.status), 'Agent authentication is still required')
assert.equal(unauthenticated.headers.get('access-control-allow-origin'), 'http://localhost:8090', 'Authentication errors are readable by approved origin')
checks++
const cookieCsrf = await fetch(`${server}/api/agent-tokens`, { method: 'POST', headers: {
  Origin: 'http://localhost:8090', Cookie: 'fapiauth=deliberately-invalid-test-cookie', 'Content-Type': 'application/json',
}, body: '{}' })
assert.equal(cookieCsrf.status, 403, 'Cross-origin cookie CSRF remains blocked')
checks++
console.log(`${checks} real-server HTTPS/CORS/CSRF checks passed against ${server}; certificate verification remained enabled.`)
