let panels = 0

/**
 * AI Act Art. 50(5): the notice that answers come from an AI must reach people who use a screen reader, at the
 * latest at their first interaction. The SDK's panel shows it as a line under the composer, but a screen reader in
 * forms mode reads only the field it is in. This names the line as the composer's description (`aria-describedby`),
 * so the notice is read each time the composer gets focus, in the panel's language. Remove when the SDK's panel
 * links the two itself.
 */
export function linkAiNotice(root: HTMLElement): () => void {
	const id = `fadenstack-ai-notice-${++panels}`
	const apply = () => {
		const composer = root.querySelector<HTMLTextAreaElement>('.fdn-composer textarea')
		if (!composer) return
		const notice = root.querySelector<HTMLElement>('.fdn-ai-notice')
		if (notice && notice.id !== id) notice.id = id
		const others = (composer.getAttribute('aria-describedby') ?? '').split(/\s+/).filter(token => token !== '' && token !== id)
		const value = [...others, ...(notice ? [id] : [])].join(' ')
		if (value === (composer.getAttribute('aria-describedby') ?? '')) return
		if (value) composer.setAttribute('aria-describedby', value)
		else composer.removeAttribute('aria-describedby')
	}
	apply()
	// Vue may replace the composer or the notice (sign-in, the off-switch, a language change); attribute changes made
	// here are not observed, so this cannot loop.
	const observer = new MutationObserver(apply)
	observer.observe(root, { childList: true, subtree: true })
	return () => observer.disconnect()
}
