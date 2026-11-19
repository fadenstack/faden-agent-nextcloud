import { recommended } from '@nextcloud/eslint-config'

// Until after the 8 Dec preview the built app must stay byte for byte as it is, so this config leaves out
// the rewrites that would change the built code. Lift both exceptions after 8 Dec.
const SCOPED_STYLE_COMPONENTS = [
	// A scoped style's id is a hash of the component's source, so any reformatting changes the built code.
	'src/components/ActionHistory.vue',
	'src/components/AssistantActivity.vue',
	'src/components/AssistantDetails.vue',
	'src/components/DavApprovalFields.vue',
	'src/components/EngineConnections.vue',
	'src/components/EngineWorkspacePane.vue',
	'src/components/NextcloudApproval.vue',
]

export default [
	...recommended,
	{
		rules: {
			'jsdoc/require-jsdoc': 'off',
			'jsdoc/require-param': 'off',
			'vue/first-attribute-linebreak': 'off',
			'vue/no-v-html': 'off',
			'no-void': ['error', { allowAsStatement: true }],
			'no-control-regex': 'off',
			'no-useless-constructor': 'off',
			// Uses inside a callback run after the declaration; the same-scope check stays on.
			'@typescript-eslint/no-use-before-define': ['error', { functions: false, variables: false }],
			// These reorder imports or rename attributes and events, which changes the built code.
			'perfectionist/sort-imports': 'off',
			'vue/attribute-hyphenation': 'off',
			'vue/v-on-event-hyphenation': 'off',
			'vue/custom-event-name-casing': 'off',
			'antfu/top-level-function': 'off',
		},
	},
	{
		files: SCOPED_STYLE_COMPONENTS,
		rules: {
			'curly': 'off',
			'@stylistic/arrow-parens': 'off',
			'@stylistic/exp-list-style': 'off',
			'@stylistic/indent': 'off',
			'@stylistic/max-statements-per-line': 'off',
			'@stylistic/member-delimiter-style': 'off',
			'import-extensions/ban-inline-type-imports': 'off',
			'perfectionist/sort-named-imports': 'off',
		},
	},
]
