import type { PlacementReport } from '@fadenstack/client'
import type { ContentOutcome, ContentProposal } from './client.ts'

export type EnginePlacement = 'reported' | 'unconfirmed' | 'not-applicable'
export type PlacementReporter = (report: PlacementReport, signal: AbortSignal) => Promise<void>

/** Ancillary audit only. A failed report must never invalidate or repeat a native commitment. */
export async function reportEnginePlacement(proposal: ContentProposal, receipt: ContentOutcome, report: PlacementReporter, signal: AbortSignal): Promise<EnginePlacement> {
	const provider = proposal.provider
	if (!['windmill', 'n8n'].includes(provider) || proposal.toolName !== `nc_${provider}_draft`) return 'not-applicable'
	try {
		if (signal.aborted || receipt.outcome !== 'verified' || receipt.continue || receipt.provider !== provider || receipt.target !== proposal.target
			|| receipt.history !== 'audit-only' || !/^[a-f0-9]{32}$/.test(receipt.actionId)
			|| receipt.result?.inactiveDraft !== true || receipt.result?.originStored !== true) return 'unconfirmed'
		const json = proposal.payload.definitionJson
		if (typeof json !== 'string' || new TextEncoder().encode(json).length > 131072) return 'unconfirmed'
		const value = JSON.parse(json)
		const encoded = provider === 'n8n' ? value.description?.match(/<!-- fadenstack-provenance:([A-Za-z0-9+/=]+) -->$/)?.[1] : undefined
		const origin = provider === 'windmill'
			? value.schema?.['x-fadenstack-provenance']
			: encoded ? JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(Uint8Array.from(atob(encoded), c => c.charCodeAt(0)))) : undefined
		const record = origin?.signed_record?.record
		const media = provider === 'windmill' ? 'application/vnd.windmill.flow+json' : 'application/vnd.n8n.workflow+json'
		if (origin?.contract !== `fadenstack/${provider}-origin/v1` || origin.ai_generated !== true
			|| record?.contract !== 'provenance/v1' || typeof record.id !== 'string' || !/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(record.id)
			|| record.media_type !== media || record.edit_kind !== 'generated') return 'unconfirmed'
		// No graph, paths, native identities, credentials or unsigned previous carrier leave Nextcloud.
		await report({
			record_id: record.id,
			tool: proposal.toolName,
			sink_kind: `nextcloud.${provider}.draft`,
			media_type: media,
			edit_kind: 'generated',
			length_class: json.length < 256 ? 'short' : json.length < 4096 ? 'medium' : 'long',
			written: true,
			layers: [{ layer: 'metadata', method: provider === 'windmill' ? 'windmill-schema-extension' : 'n8n-description-comment', applied: true, verified: true }],
		},
		AbortSignal.any([signal, AbortSignal.timeout(5000)]))
		return 'reported'
	} catch {
		// A timeout can mean that the report was stored. No automatic second report or native write.
		return 'unconfirmed'
	}
}
