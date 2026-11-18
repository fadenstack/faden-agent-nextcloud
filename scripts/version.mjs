// The app's version, in the four places that carry it: package.json, package-lock.json (its root), appinfo/info.xml and
// src/version.ts (what the app reports to the Fadenstack server and the pairing).
//   node scripts/version.mjs 0.2.0-beta.1     set it everywhere
//   node scripts/version.mjs --check          all four agree
//   node scripts/version.mjs --check 0.2.0    all four agree and are this version (the release workflow passes the tag)
import { readFile, writeFile } from 'node:fs/promises'

const SEMVER = /^\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?$/
const places = {
	'package.json': text => JSON.parse(text).version,
	'package-lock.json': text => { const lock = JSON.parse(text); return lock.version === lock.packages?.['']?.version ? lock.version : undefined },
	'appinfo/info.xml': text => /<version>([^<]+)<\/version>/.exec(text)?.[1],
	'src/version.ts': text => /export const APP_VERSION = '([^']+)'/.exec(text)?.[1],
}

async function read() {
	return Object.fromEntries(await Promise.all(Object.entries(places).map(async ([file, get]) => [file, get(await readFile(file, 'utf8'))])))
}

const [first, second] = process.argv.slice(2)
if (first === '--check') {
	const found = await read()
	const versions = new Set(Object.values(found))
	if (versions.size !== 1 || versions.has(undefined)) throw new Error('The versions differ: ' + JSON.stringify(found))
	const [version] = versions
	if (second !== undefined && second !== version) throw new Error(`The tag says ${second}, the app says ${version}.`)
	console.log(version)
} else if (first && SEMVER.test(first)) {
	const before = (await read())['package.json']
	const swap = (text, pattern, count = 1) => {
		let left = count
		const changed = text.replace(pattern, (...m) => left-- > 0 ? m[1] + first + m[2] : m[0])
		if (left !== 0) throw new Error('Version not found in the expected place.')
		return changed
	}
	const escaped = before.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	await writeFile('package.json', swap(await readFile('package.json', 'utf8'), /("version":\s*")[^"]+(")/g))
	await writeFile('package-lock.json', swap(await readFile('package-lock.json', 'utf8'), new RegExp(`("version":\\s*")${escaped}(")`, 'g'), 2))
	await writeFile('appinfo/info.xml', swap(await readFile('appinfo/info.xml', 'utf8'), /(<version>)[^<]+(<\/version>)/g))
	await writeFile('src/version.ts', swap(await readFile('src/version.ts', 'utf8'), /(export const APP_VERSION = ')[^']+(')/g))
	const after = await read()
	if (new Set(Object.values(after)).size !== 1) throw new Error('Not all places changed: ' + JSON.stringify(after))
	console.log(`${before} -> ${first}`)
} else {
	console.error('Usage: node scripts/version.mjs <version> | --check [version]')
	process.exit(2)
}
