import type { FileApi, FileContext } from './types.ts'

/** Only the latest metadata lookup can change the selection. Never reads file bytes. */
export class SelectedContext {
	current: FileContext | undefined
	#revision = 0
	constructor(readonly api: FileApi) {}
	async select(fileId: number, signal?: AbortSignal): Promise<FileContext | undefined> {
		const revision = ++this.#revision
		this.current = undefined
		let context: FileContext
		try {
			context = await this.api.select(fileId, signal)
		} catch (error) {
			if (revision !== this.#revision) {
				return undefined
			}
			throw error
		}
		if (revision !== this.#revision) {
			return undefined
		}
		this.current = Object.freeze({ ...context, file: Object.freeze({ ...context.file }) })
		return this.current
	}

	clear(): void {
		++this.#revision
		this.current = undefined
	}

	snapshot(): FileContext | undefined { return this.current }
}
