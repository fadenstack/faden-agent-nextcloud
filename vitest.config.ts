import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { commandSdkAliases } from './scripts/command-sdk.ts'
export default defineConfig({
	plugins: [vue()],
	resolve: { alias: commandSdkAliases(), dedupe: ['vue'] },
	test: {
		environment: 'jsdom',
		include: ['tests/unit/**/*.test.ts'],
		// Native Vue components import their styles; let Vite handle these instead of Node's ESM loader.
		server: { deps: { inline: [/@nextcloud[\\/]vue/] } },
	},
})
