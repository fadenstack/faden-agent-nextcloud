import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'
import { commandSdkAliases } from './scripts/command-sdk.ts'

// Nextcloud styles every button and text field (`button:not(.button-vue)`), which outranks the SDK stylesheet's
// one-class rules. Scoped under the drawer's panel, @fadenstack/ui-web's rules win there, and only there.
const scopeSdkStyles = {
	postcssPlugin: 'fadenstack-scope-ui-web',
	Once(root: { walkRules(callback: (rule: { selectors: string[]; parent?: { type: string; name?: string } }) => void): void }, { result }: { result: { opts: { from?: string } } }) {
		if (!/[\\/](?:@fadenstack|packages)[\\/]ui-web[\\/]/.test(result.opts.from ?? '')) return
		root.walkRules((rule) => {
			if (rule.parent?.type === 'atrule' && /keyframes$/i.test(rule.parent.name ?? '')) return
			rule.selectors = rule.selectors.map((selector) => `.fadenstack-panel ${selector}`)
		})
	},
}

export default createAppConfig(
	{
		assistant: resolve(join('src', 'main.ts')),
		admin: resolve(join('src', 'admin.ts')),
	},
	{
		createEmptyCSSEntryPoints: true,
		extractLicenseInformation: true,
		thirdPartyLicense: false,
		config: { css: { postcss: { plugins: [scopeSdkStyles] } }, resolve: { alias: commandSdkAliases(), dedupe: ['vue'] } },
	},
)
