import { FakeGateway as SdkGateway, type FakeGatewayOptions } from '@fadenstack/testing'

/** Explicit unit-only 0.7.1 policy fixture; the SDK FakeGateway has no tools/check route. */
export class FakeGateway extends SdkGateway {

	readonly dispatchChecks: Record<string, unknown>[] = []
	denyDispatch = false

	constructor(options: FakeGatewayOptions = {}) {
		super({ principal: true, ...options })
		const send = this.fetch
		Object.defineProperty(this, 'fetch', { value: async (input: RequestInfo | URL, init?: RequestInit) => {
			const request = new Request(input, init)
			const url = new URL(request.url)
			const check = url.pathname.endsWith('/tools/check') && request.method === 'POST'
			const body = check ? await request.clone().json() as Record<string, unknown> : undefined
			const response = await send(input, init)
			if (url.pathname.endsWith('/profile') && response.ok) {
				const profile = await response.json()
				return Response.json({ ...profile, features: [...profile.features, 'host_tools.dispatch_check'] })
			}
			if (!body || response.status !== 404) return response
			const profileResponse = await send(url.href.replace(/tools\/check$/, 'profile'), { headers: request.headers })
			if (!profileResponse.ok) return profileResponse
			const profile = await profileResponse.json()
			const entry = profile.host_tool_contract.find((tool: { name: string }) => tool.name === body.name)
			const risk = entry?.risk ?? 'unknown'
			const rule = entry?.approval === 'always'
			const mode = body.tool_mode
			const decision = this.denyDispatch && body.phase === 'dispatch' ? 'deny'
				: rule || risk === 'unknown' || risk === 'destructive' || (risk === 'write' && mode === 'ask') ? 'ask' : 'run'
			this.dispatchChecks.push(body)
			return Response.json({ decision, tool_class: risk, effective_tool_mode: mode, required_by_rule: rule,
				message: 'Unit 0.7.1 policy decision', principal: profile.principal,
				profile_stamp: await digest({ contract: profile.host_tool_contract, modes: profile.tool_modes }),
				arguments_stamp: await digest(body.arguments), decision_stamp: await digest({ risk, rule, mode }),
			})
		} })
	}

}
async function digest(value: unknown): Promise<string> {
	return Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(JSON.stringify(value)))), x => x.toString(16).padStart(2, '0')).join('')
}
