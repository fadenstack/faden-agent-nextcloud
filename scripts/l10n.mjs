import { readFile, writeFile } from 'node:fs/promises'
const { translations, pluralForm } = JSON.parse(await readFile('l10n/de.json', 'utf8'))
await writeFile('l10n/de.js', `OC.L10N.register("fadenstack_agent", ${JSON.stringify(translations, null, 2)}, ${JSON.stringify(pluralForm)});\n`)
