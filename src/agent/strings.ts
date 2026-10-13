import { builtInStrings, withStrings, type UiStrings } from '@fadenstack/ui'

/**
 * The SDK's texts in every language it brings, with what is different in Nextcloud: a file write (the
 * "destructive" class) always needs the user's own approval of the exact proposal, also in Auto, and Nextcloud
 * keeps the file's earlier versions, so it is not "cannot be undone". Everything else is the SDK's wording.
 */
const fileActions: Record<'en' | 'de', { why: string; label: string; ask: string; auto: string }> = {
	en: {
		why: 'This file action needs your individual approval.',
		label: 'Individual approval',
		ask: 'Approve file actions one at a time.',
		auto: 'Runs reading tools; file actions still need your individual approval.',
	},
	de: {
		why: 'Diese Dateiaktion benötigt Ihre einzelne Freigabe.',
		label: 'Einzelne Freigabe',
		ask: 'Dateiaktionen einzeln freigeben.',
		auto: 'Führt Lese-Tools aus; Dateiaktionen benötigen weiterhin Ihre einzelne Freigabe.',
	},
}

function inNextcloud(base: UiStrings, words: typeof fileActions.en): UiStrings {
	return withStrings({
		approvalWhy: { ...base.approvalWhy, destructive: words.why },
		approvalClass: { ...base.approvalClass, destructive: words.label },
		toolClass: { ...base.toolClass, destructive: words.label },
		modes: {
			...base.modes,
			ask: { ...base.modes.ask, description: words.ask },
			auto: { ...base.modes.auto, description: words.auto },
		},
	}, base)
}

/** The languages the user can pick in the panel's settings. */
export const nextcloudLanguages: Readonly<Record<string, UiStrings>> = {
	...builtInStrings,
	en: inNextcloud(builtInStrings.en!, fileActions.en),
	de: inNextcloud(builtInStrings.de!, fileActions.de),
}
