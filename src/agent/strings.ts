import { builtInStrings, withStrings, type UiStrings } from '@fadenstack/ui'

/**
 * The SDK's texts in every language it brings, with what is different in Nextcloud: a file write (the
 * "destructive" class) always needs the user's own approval of the exact proposal, also in Auto, and Nextcloud
 * keeps the file's earlier versions, so it is not "cannot be undone". Everything else is the SDK's wording.
 */
const fileActions: Record<'en' | 'de' | 'es' | 'fr' | 'zh-Hans', { why: string; label: string; ask: string; auto: string }> = {
	en: {
		why: 'This action needs your individual approval.',
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
	es: {
		why: 'Esta acción necesita su aprobación individual.',
		label: 'Aprobación individual',
		ask: 'Apruebe las acciones de archivos una por una.',
		auto: 'Ejecuta las herramientas de lectura; las acciones de archivos siguen necesitando su aprobación individual.',
	},
	fr: {
		why: 'Cette action nécessite votre approbation individuelle.',
		label: 'Approbation individuelle',
		ask: 'Approuvez les actions sur les fichiers une par une.',
		auto: 'Exécute les outils de lecture ; les actions sur les fichiers nécessitent toujours votre approbation individuelle.',
	},
	'zh-Hans': {
		why: '此操作需要您单独审批。',
		label: '单独审批',
		ask: '逐一审批文件操作。',
		auto: '运行读取工具；文件操作仍需您单独审批。',
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
	es: inNextcloud(builtInStrings.es!, fileActions.es),
	fr: inNextcloud(builtInStrings.fr!, fileActions.fr),
	'zh-Hans': inNextcloud(builtInStrings['zh-Hans']!, fileActions['zh-Hans']),
}
