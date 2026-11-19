/** Files31–35 has no public selection getter. Visible row IDs are hints, never authority. */
function selectedRow(page: Document): number | undefined {
	const rows = [...page.querySelectorAll<HTMLElement>('.files-list__row')]
		.filter((row) => row.querySelector<HTMLInputElement>('input[type="checkbox"]')?.checked)
	if (rows.length !== 1) {
		return undefined
	}
	const id = rows[0]!.getAttribute('data-cy-files-list-row-fileid') ?? ''
	return /^[1-9][0-9]{0,14}$/.test(id) ? Number(id) : undefined
}

export function currentFileSelection(page: Document = document): number | undefined {
	try {
		const frame = page.querySelector<HTMLIFrameElement>('#fadenstack-native-frame')
		return frame ? (frame.contentDocument ? selectedRow(frame.contentDocument) : undefined) : selectedRow(page)
	} catch { return undefined }
}

/** Follow the owned same-origin child without dropping included context on an agent reload. */
export function observeFileSelection(page: Document, changed: (id: number | undefined) => void, busy: () => boolean): () => void {
	let previous = currentFileSelection(page)
	let stopped = false
	let frame: HTMLIFrameElement | null = null
	let child: Document | null = null
	let childObserver: MutationObserver | undefined
	let queued = false
	let pending: number | undefined
	let timer: ReturnType<typeof setTimeout> | undefined
	const publish = (id: number | undefined) => {
		if (stopped) {
			return
		}
		if (busy()) {
			if (id === previous && !queued) {
				return
			}
			pending = id
			queued = true
			if (timer === undefined) {
				timer = setTimeout(flush, 50)
			}
			return
		}
		queued = false
		if (timer !== undefined) {
			clearTimeout(timer)
			timer = undefined
		}
		if (id === previous) {
			return
		}
		previous = id
		changed(id)
	}
	const flush = () => {
		timer = undefined
		if (!stopped && queued) {
			publish(pending)
		}
	}
	const childChanged = () => {
		if (!child) {
			return
		}
		const id = selectedRow(child)
		if (id !== undefined) {
			publish(id)
		}
	}
	const childInput = (event: Event) => {
		const target = event.target as Element | null
		if (child && target?.closest?.('input[type="checkbox"]') && target.closest('.files-list__row, .files-list__thead')) {
			publish(selectedRow(child))
		}
	}
	const attach = () => {
		let next: Document | null = null
		try {
			next = frame?.contentDocument ?? null
		} catch { /* inaccessible editors grant no context */ }
		if (next === child) {
			return
		}
		childObserver?.disconnect()
		child?.removeEventListener('change', childInput)
		child = next
		if (child) {
			child.addEventListener('change', childInput)
			childObserver = new MutationObserver(childChanged)
			childObserver.observe(child, { subtree: true, childList: true, attributes: true, attributeFilter: ['aria-selected', 'class'] })
			childChanged()
		}
	}
	const update = () => {
		const next = page.querySelector<HTMLIFrameElement>('#fadenstack-native-frame')
		if (frame !== next) {
			frame?.removeEventListener('load', attach)
			frame = next
			frame?.addEventListener('load', attach)
		}
		if (frame) {
			attach()
		} else {
			attach()
			publish(selectedRow(page))
		}
	}
	page.addEventListener('change', update)
	const parent = new MutationObserver(update)
	parent.observe(page.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['aria-selected', 'class'] })
	update()
	return () => {
		stopped = true
		if (timer !== undefined) {
			clearTimeout(timer)
		}
		parent.disconnect()
		childObserver?.disconnect()
		child?.removeEventListener('change', childInput)
		frame?.removeEventListener('load', attach)
		page.removeEventListener('change', update)
	}
}
