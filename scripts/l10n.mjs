import { readdir, readFile, writeFile } from 'node:fs/promises'
// Every catalogue l10n/<language>.json becomes the l10n/<language>.js Nextcloud loads; English is the source text.
for (const name of (await readdir('l10n')).filter(file => file.endsWith('.json')).sort()) {
	const { translations, pluralForm } = JSON.parse(await readFile(`l10n/${name}`, 'utf8'))
	await writeFile(`l10n/${name.replace(/\.json$/, '.js')}`, `OC.L10N.register("fadenstack_agent", ${JSON.stringify(translations, null, 2)}, ${JSON.stringify(pluralForm)});\n`)
}
