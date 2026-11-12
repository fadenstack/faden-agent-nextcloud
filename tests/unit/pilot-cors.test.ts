import { afterEach, describe, expect, it } from 'vitest'
import { execFileSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const owned: string[] = []
afterEach(() => { for (const root of owned.splice(0)) rmSync(root, { recursive: true }) })
function fixture() {
	const root = mkdtempSync(join(tmpdir(), 'nc-sdk071-cors-')); owned.push(root)
	writeFileSync(join(root, 'nginx.conf'), '# HTTP\n')
	writeFileSync(join(root, 'nginx-tls.conf'), '# HTTPS\n')
	const locations = ['/api/', '/v1/', '@faden_maintenance_api', '@faden_maintenance_openai', '@faden_too_many'].map(p => `location ${p} {\n}\n`).join('')
	writeFileSync(join(root, 'locations.conf'), locations)
	return { root, locations }
}
describe('scoped Nextcloud browser CORS deployment helper', () => {
	it('previews the new SDK wire without granting console/admin cookie access or writing files', () => {
		const { root, locations } = fixture()
		const output = execFileSync(process.platform === 'win32' ? 'python' : 'python3', ['scripts/configure-pilot-cors.py', '--nginx-dir', root,
			'--agent', 'nextcloud-test', '--origin', 'http://127.0.0.1:18093'], { encoding: 'utf8', timeout: 5000 })
		for (const route of ['/api/agent-tokens/pairings 1;', '/api/agent-tokens/pairings/token 1;', '/api/agent-tokens/revoke 1;', '/v1/provenance/anchors 1;']) expect(output).toContain(route)
		for (const name of ['X-Faden-Client-Supports', 'X-Faden-Step-Up', 'X-Faden-Provenance', 'Retry-After']) expect(output).toContain(name)
		expect(output).toContain('Access-Control-Expose-Headers')
		expect(output).toContain('~^/v1/agents/nextcloud\\-test(?:/|$) 1;')
		expect(output).not.toContain('/api/agent-tokens/pairings/approve 1;')
		expect(output).not.toContain('add_header Access-Control-Allow-Credentials')
		expect(output).toContain('default 0;')
		expect(readFileSync(join(root, 'locations.conf'), 'utf8')).toBe(locations)
	})
	it('refuses a non-local insecure origin before producing a deployable configuration', () => {
		const { root } = fixture()
		expect(() => execFileSync(process.platform === 'win32' ? 'python' : 'python3', ['scripts/configure-pilot-cors.py', '--nginx-dir', root,
			'--agent', 'nextcloud-test', '--origin', 'http://untrusted.example'], { stdio: 'pipe', timeout: 5000 })).toThrow()
	})
})
