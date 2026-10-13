import { onScopeDispose, ref } from 'vue'

/** Vue observes a counter, never proxies an SDK object or its private fields. */
export function useObservable(model: { readonly version: number; subscribe(listener: () => void): () => void }) {
	const version = ref(model.version)
	const unsubscribe = model.subscribe(() => { version.value = model.version })
	onScopeDispose(unsubscribe)
	return version
}
