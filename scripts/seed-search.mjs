import assert from 'node:assert/strict'
import { readFile, mkdir, writeFile } from 'node:fs/promises'
import { pathToFileURL } from 'node:url'
import { User } from '../tests/integration/client.mjs'

export async function seedSearch(user) {
  const folder = 'Fadenstack Search Pilot 2026-10-13'
  const created = await user.dav(folder,'MKCOL')
  assert.ok([201,405].includes(created.status),'Create or reuse the named pilot fixture folder')
  for(const [name,modified] of [['Supplier Contract 2026-10-12.md','2026-10-12T09:30:00Z'],['Supplier Contract 2026-09-01.md','2026-09-01T09:30:00Z']]) {
    const content = await readFile(new URL('../tests/fixtures/search/'+encodeURIComponent(name),import.meta.url),'utf8')
    const response = await user.dav(folder+'/'+name,'PUT',content,{'X-OC-MTime':String(Date.parse(modified)/1000)})
    assert.ok([201,204].includes(response.status),'Seed named synthetic contract fixture')
  }
  const fixture = { folder, folderId: await user.id(folder), latestId: await user.id(folder+'/Supplier Contract 2026-10-12.md') }
  await mkdir('build/live-search',{recursive:true})
  await writeFile('build/live-search/fixtures.json',JSON.stringify(fixture,null,2)+'\n')
  return fixture
}
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const user = new User(process.env.NEXTCLOUD_USER || 'pilot-admin',process.env.NEXTCLOUD_PASSWORD || 'local-pilot-only')
  await user.login()
  console.log(JSON.stringify(await seedSearch(user)))
}
