import { readdirSync, readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { builtInStrings } from '@fadenstack/ui'
import { nextcloudLanguages } from '../../src/agent/strings.ts'

// The app's own screens in the SDK panel's five languages (owner, 18 Nov): every string the app shows through t() or
// n() has a translation in each catalogue, with the same placeholders, and the catalogues hold nothing else.
const root = resolve(process.cwd())
const quoted = String.raw`'((?:[^'\\]|\\.)*)'`
const single = new RegExp(String.raw`\bt\(\s*'fadenstack_agent'\s*,\s*` + quoted, 'g')
const plural = new RegExp(String.raw`\bn\(\s*'fadenstack_agent'\s*,\s*` + quoted + String.raw`\s*,\s*` + quoted, 'g')
const unescape = (text: string) => text.replace(/\\'/g, '\'')

function sources(dir: string): string[] {
	return readdirSync(dir, { withFileTypes: true }).flatMap(entry => entry.isDirectory()
		? sources(join(dir, entry.name))
		: /\.(ts|vue)$/.test(entry.name) ? [join(dir, entry.name)] : [])
}
const keys = new Map<string, { plural: boolean }>()
for (const file of sources(join(root, 'src'))) {
	const text = readFileSync(file, 'utf8')
	for (const match of text.matchAll(single)) keys.set(unescape(match[1]!), { plural: false })
	for (const match of text.matchAll(plural)) keys.set(`_${unescape(match[1]!)}_::_${unescape(match[2]!)}_`, { plural: true })
}
const placeholders = (text: string) => [...text.matchAll(/\{[a-zA-Z]+\}/g)].map(match => match[0]).sort()
const forms = { de: 2, es: 2, fr: 2, zh_CN: 1 } as const

describe('the app\'s translations', () => {
	it('finds the app\'s strings', () => {
		expect(keys.size).toBeGreaterThan(400)
		expect([...keys.values()].filter(entry => entry.plural)).toHaveLength(2)
	})
	it.each(Object.entries(forms))('%s has every string, with its placeholders, and nothing else', (language, count) => {
		const catalogue = JSON.parse(readFileSync(join(root, 'l10n', `${language}.json`), 'utf8')) as { translations: Record<string, string | string[]> }
		const translations = catalogue.translations
		expect(Object.keys(translations).filter(key => !keys.has(key))).toEqual([])
		expect([...keys.keys()].filter(key => translations[key] === undefined)).toEqual([])
		for (const [key, entry] of keys) {
			const value = translations[key]!
			if (entry.plural) {
				expect(Array.isArray(value) && value.length === count, key).toBe(true)
				const [singular, other] = key.slice(1, -1).split('_::_')
				for (const form of value as string[]) expect(placeholders(form), key).toEqual(placeholders(other ?? singular!))
			} else {
				expect(typeof value === 'string' && value.trim().length > 0, key).toBe(true)
				expect(placeholders(value as string), key).toEqual(placeholders(key))
			}
		}
	})
	it.each(Object.keys(forms))('%s: the generated l10n/*.js matches its catalogue', language => {
		const catalogue = JSON.parse(readFileSync(join(root, 'l10n', `${language}.json`), 'utf8')) as { translations: Record<string, unknown>; pluralForm: string }
		const script = readFileSync(join(root, 'l10n', `${language}.js`), 'utf8')
		expect(script).toBe(`OC.L10N.register("fadenstack_agent", ${JSON.stringify(catalogue.translations, null, 2)}, ${JSON.stringify(catalogue.pluralForm)});\n`)
	})
})

// The SDK's panel texts, with what is different in Nextcloud, in every language the panel offers: a file action always
// needs its own approval (also in Auto) and Nextcloud keeps earlier versions, so it is not "cannot be undone".
describe('the panel texts in Nextcloud', () => {
	it.each(Object.keys(builtInStrings))('%s says that file actions need individual approval', language => {
		const sdk = builtInStrings[language]!, app = nextcloudLanguages[language]!
		expect(app).toBeDefined()
		expect(app.approvalClass.destructive).not.toBe(sdk.approvalClass.destructive)
		expect(app.approvalWhy.destructive).not.toBe(sdk.approvalWhy.destructive)
		expect(app.modes.ask.description).not.toBe(sdk.modes.ask.description)
		expect(app.modes.auto.description).not.toBe(sdk.modes.auto.description)
		expect(app.modes.ask.label).toBe(sdk.modes.ask.label)
	})
})
