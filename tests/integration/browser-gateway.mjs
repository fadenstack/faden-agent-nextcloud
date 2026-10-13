// Browser smoke fixture only: real SDK + real Nextcloud files, simulated model.
// Never loaded by the app and never substitutes for live Fadenstack acceptance.
import { createServer } from 'node:http'
import { FakeGateway, lastToolResults, lastUserText } from '@fadenstack/testing'

const tools = ['nc_get_selected_file', 'nc_read_file', 'nc_list_folder', 'nc_create_text_file', 'nc_update_text_file']
const gateway = new FakeGateway({
  serverUrl: 'http://127.0.0.1:18781',
  agents: [{ slug: 'browser-smoke', version: 1, contract: tools.map(name => ({ name, risk: name.includes('text_file') ? 'destructive' : 'read' })) }],
  users: [{ email: 'smoke', password: 'local-smoke-only' }],
  model: request => {
    const results = lastToolResults(request)
    if (results.has('browser-create')) {
      const result = JSON.parse(results.get('browser-create'))
      if (!result.file) return { text: 'The proposed operation was not committed.' }
      return { text: `Created the approved file: [${result.file.name}](${result.file.url}).` }
    }
    const input = lastUserText(request)
    if (/create.*markdown/i.test(input)) {
      return { toolCalls: [{ id: 'browser-create', name: 'nc_create_text_file', arguments: { filename: `browser-smoke-${Date.now()}.md`, content: '# PDF browser smoke\n\nThis is a simulated summary used only to verify the SDK approval and real Nextcloud file creation.' } }] }
    }
    if (!JSON.stringify(request.messages).includes('[Page 1]')) return { text: 'PDF page text was not supplied. This smoke check failed.' }
    return { text: 'PDF extraction verified: page text reached the real SDK. This response is from the test simulation, not a live AI model.', delayMs: 30 }
  },
})

const server = createServer(async (req, res) => {
  const origin = req.headers.origin
  if (['http://localhost:8090', 'http://localhost:8088'].includes(origin)) {
    res.setHeader('Access-Control-Allow-Origin', origin)
    res.setHeader('Vary', 'Origin')
    res.setHeader('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS')
    res.setHeader('Access-Control-Allow-Headers', 'authorization,content-type,x-faden-tool-mode,x-faden-events,x-faden-conversation-id,x-faden-turn-id')
  }
  if (req.method === 'OPTIONS') { res.writeHead(204); res.end(); return }
  const chunks = []
  for await (const chunk of req) chunks.push(chunk)
  const response = await gateway.handle(new Request(`http://127.0.0.1:18781${req.url}`, {
    method: req.method, headers: req.headers, ...(chunks.length ? { body: Buffer.concat(chunks) } : {}),
  }))
  response.headers.forEach((value, key) => res.setHeader(key, value))
  res.writeHead(response.status)
  if (response.body) for await (const chunk of response.body) res.write(chunk)
  res.end()
})
server.listen(18781, '127.0.0.1', () => console.log('SDK browser simulation on loopback port 18781; no credentials or document bodies are logged.'))
