import { cp, mkdir, mkdtemp, readdir, readFile, writeFile } from 'node:fs/promises'
import { join } from 'node:path'
import { execFileSync } from 'node:child_process'

const id = 'fadenstack_agent'
const version = JSON.parse(await readFile('package.json', 'utf8')).version
await mkdir('build', { recursive: true })
const stage = await mkdtemp(join('build', 'package-'))
const app = join(stage, id)
await mkdir(app)
for (const item of ['appinfo', 'lib', 'templates', 'img', 'js', 'css', 'dist', 'l10n', 'docs', 'contracts', 'src', 'tests', 'vite.config.ts', 'vitest.config.ts', 'tsconfig.json', '.eslintrc.cjs', 'stylelint.config.cjs', '.php-cs-fixer.dist.php', 'README.md', 'LICENSE', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json']) {
  await cp(item, join(app, item), { recursive: true, filter: source => !source.endsWith('.map') })
}
await mkdir(join(app, 'scripts'))
await cp('scripts/package.mjs', join(app, 'scripts', 'package.mjs'))
await cp('scripts/clean.mjs', join(app, 'scripts', 'clean.mjs'))
await cp('scripts/l10n.mjs', join(app, 'scripts', 'l10n.mjs'))
await cp('scripts/seed-search.mjs', join(app, 'scripts', 'seed-search.mjs'))
const attribution = join(app, 'third-party')
await cp('licenses', join(app, 'licenses'), { recursive: true })
await cp('scripts/configure-pilot-cors.py', join(app, 'scripts', 'configure-pilot-cors.py'))
await mkdir(attribution)
const lock = JSON.parse(await readFile('package-lock.json', 'utf8'))
const inventory = []
for (const [path, metadata] of Object.entries(lock.packages)) {
  if (!path.startsWith('node_modules/') || metadata.dev || metadata.devOptional) continue
  const name = path.replaceAll('node_modules/', '').replaceAll('/', '_')
  inventory.push(`${path}: ${metadata.version}; ${metadata.license || 'review package license'}`)
  for (const file of await readdir(path).catch(() => [])) {
    if (/^(license|licence|notice|copyright)(\.|$)/i.test(file)) await cp(join(path, file), join(attribution, `${name}-${file}`), { recursive: true })
  }
}
await writeFile(join(attribution, 'inventory.txt'), inventory.sort().join('\n') + '\n')
const output = join('build', `${id}-${version}.tar.gz`)
execFileSync('tar', ['-czf', output, '-C', stage, id], { stdio: 'inherit' })
console.log(`Installable app: ${output}`)
