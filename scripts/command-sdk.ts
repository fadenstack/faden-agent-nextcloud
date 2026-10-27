import { readFileSync } from 'node:fs'
import { resolve, sep } from 'node:path'

/** Explicit offline compatibility fixture; normal builds always use the lockfile. */
export function commandSdkAliases(): { find: string; replacement: string }[] {
	const input = process.env.FADENSTACK_COMMAND_SDK
	if (!input) return []
	const root = resolve(input)
	const aliases: { find: string; replacement: string }[] = []
	for (const name of ['client', 'agents', 'ui', 'ui-web', 'ui-vue', 'testing', 'provenance']) {
		const directory = resolve(root, 'packages', name)
		const metadata = JSON.parse(readFileSync(resolve(directory, 'package.json'), 'utf8'))
		if (metadata.name !== `@fadenstack/${name}` || metadata.version !== '0.3.0-rc.1') throw new Error('The explicit command SDK fixture must be the reviewed 0.3.0-rc.1 build.')
		for (const [key, value] of Object.entries(metadata.exports) as [string, string | { import?: string }][]) {
			const path = typeof value === 'string' ? value : value.import
			if (!path) continue
			const target = resolve(directory, path)
			if (!target.startsWith(directory + sep)) throw new Error('Invalid SDK export.')
			readFileSync(target)
			aliases.push({ find: `@fadenstack/${name}${key === '.' ? '' : key.slice(1)}`, replacement: target })
		}
	}
	console.warn('Using the explicit unreleased command SDK fixture; this is not a normal release build.')
	return aliases.sort((a, b) => b.find.length - a.find.length)
}
