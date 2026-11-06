import { cp, mkdir } from 'node:fs/promises'
import { execFileSync } from 'node:child_process'
await mkdir('build/php-runtime', { recursive: true })
for (const file of ['composer.json', 'composer.lock']) await cp(file, 'build/php-runtime/' + file)
execFileSync('composer', ['install', '--working-dir=build/php-runtime', '--no-dev', '--no-interaction', '--no-progress', '--prefer-dist', '--optimize-autoloader'], { stdio: 'inherit' })
