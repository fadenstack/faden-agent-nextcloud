module.exports = {
	extends: [
		'@nextcloud',
		'@nextcloud/eslint-config/typescript',
	],
	parserOptions: { parser: '@typescript-eslint/parser' },
	rules: {
		'jsdoc/require-jsdoc': 'off',
		'vue/first-attribute-linebreak': 'off',
		'no-void': ['error', { allowAsStatement: true }],
		'no-control-regex': 'off',
		'jsdoc/require-param': 'off',
		'vue/no-v-html': 'off',
		'no-useless-constructor': 'off',
	},
}
