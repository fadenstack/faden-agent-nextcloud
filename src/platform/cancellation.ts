/** Reads have no effect to reconcile: release the turn on Stop even if a dependency ignores its signal. */
export function interruptibleRead<T>(operation: () => Promise<T>, signal: AbortSignal): Promise<T> {
	signal.throwIfAborted()
	return new Promise<T>((resolve, reject) => {
		let settled = false
		const finish = () => {
			if (settled) {
				return false
			}
			settled = true
			signal.removeEventListener('abort', cancel)
			return true
		}
		const cancel = () => {
			if (finish()) {
				reject(signal.reason ?? new DOMException('The request was stopped.', 'AbortError'))
			}
		}
		signal.addEventListener('abort', cancel, { once: true })
		Promise.resolve().then(() => {
			signal.throwIfAborted()
			return operation()
		}).then((value) => {
			if (finish()) {
				resolve(value)
			}
		}, (error) => {
			if (finish()) {
				reject(error)
			}
		})
		if (signal.aborted) {
			cancel()
		}
	})
}
