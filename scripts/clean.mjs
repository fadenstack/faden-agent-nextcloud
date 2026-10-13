import { readFile, realpath, rm } from 'node:fs/promises'
import { resolve, relative, isAbsolute } from 'node:path'

const root = await realpath(process.cwd())
if (JSON.parse(await readFile(resolve(root, 'package.json'), 'utf8')).name !== 'fadenstack_agent') throw new Error('Run asset cleanup from the app root.')
for (const name of ['js', 'css', 'dist']) {
  const target = resolve(root, name)
  const path = relative(root, target)
  if (!path || path.startsWith('..') || isAbsolute(path)) throw new Error('Generated asset target escaped the app root.')
  await rm(target, { recursive: true, force: true })
}
