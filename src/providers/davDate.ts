/** iCalendar compact values and our ISO input values share the same native calendar date. */
export function nativeCalendarDate(value: string): string | undefined {
	const match = /^(\d{4})-?(\d{2})-?(\d{2})(?:T|$)/.exec(value)
	return match ? `${match[1]}-${match[2]}-${match[3]}` : undefined
}

export function displayDavDate(value: string): { text: string, allDay: boolean } {
	const date = nativeCalendarDate(value)
	if (!date) {
		return { text: value, allDay: false }
	}
	if (!value.includes('T')) {
		return { text: date, allDay: true }
	}
	const time = /T(\d{2}):?(\d{2})(?::?(\d{2}))?(Z)?$/.exec(value)
	if (!time) {
		return { text: value, allDay: false }
	}
	return { text: `${date} ${time[1]}:${time[2]}${time[3] && time[3] !== '00' ? ':' + time[3] : ''}${time[4] ? ' UTC' : ''}`, allDay: false }
}
