import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { APP_VERSION } from '../../src/version.ts'

// One version everywhere (scripts/version.mjs sets them; the release workflow checks them against the tag).
describe('the app\'s version', () => {
	it('is the same in package.json, its lock, appinfo/info.xml and what the app reports', () => {
		const root = process.cwd()
		const pkg = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8')) as { version: string }
		const lock = JSON.parse(readFileSync(join(root, 'package-lock.json'), 'utf8')) as { version: string; packages: Record<string, { version?: string }> }
		const info = /<version>([^<]+)<\/version>/.exec(readFileSync(join(root, 'appinfo/info.xml'), 'utf8'))?.[1]
		expect(APP_VERSION).toMatch(/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/)
		expect([pkg.version, lock.version, lock.packages['']?.version, info]).toEqual([APP_VERSION, APP_VERSION, APP_VERSION, APP_VERSION])
	})
})
