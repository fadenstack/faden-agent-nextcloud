import { execFileSync } from 'node:child_process'

const service = process.argv[2] || 'nextcloud35'
if (!['nextcloud', 'nextcloud35'].includes(service)) throw new Error('Choose a declared development service.')
function occ(args, env = []) {
  return execFileSync('docker', ['compose', 'exec', '-T', '-u', 'www-data', ...env.flatMap(value => ['-e', value]), service, 'php', 'occ', ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
}
occ(['app:enable', 'fadenstack_agent'])
for (const [key, value] of Object.entries({ server_url: 'https://10.250.10.61', agent: 'nextcloud-agent', integration_enabled: '1', development: '0' })) {
  occ(['config:app:set', 'fadenstack_agent', key, `--value=${value}`])
}
for (const user of ['pilot-alice', 'pilot-bob']) {
  try { occ(['user:add', '--password-from-env', user], ['OC_PASS=local-pilot-user']) }
  catch { if (!occ(['user:list', '--output=json']).includes(user)) throw new Error(`Could not create ${user}`) }
}
console.log(`Configured ${service}, enabled the app, and created local pilot users.`)
