/**
 * Formatting helpers.
 *
 * Replaces four copies of formatFileSize that disagreed with each other:
 * OrgDetails guarded only `bytes === 0` and rendered "NaN undefined" for null,
 * while ConvertTrialModal guarded `!bytes`. This takes the safe one.
 */

const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'] as const

/** Binary divisors with the decimal labels the existing UI uses. */
export function formatFileSize(bytes: number | null | undefined): string {
	if (!bytes || bytes <= 0) return '0 B'
	const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), UNITS.length - 1)
	return `${parseFloat((bytes / Math.pow(1024, i)).toFixed(2))} ${UNITS[i]}`
}

/**
 * The server emits 'Y-m-d H:i:s' in UTC with no trailing Z, which browsers
 * parse as local time. Normalise before formatting, or every timestamp is
 * silently shifted by the viewer's offset — which is what the existing
 * OrganizationBackup.formatDate does.
 */
function toDate(raw: string | null | undefined): Date | null {
	if (!raw) return null
	const iso = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw)
		? raw.replace(' ', 'T') + 'Z'
		: raw
	const d = new Date(iso)
	return Number.isNaN(d.getTime()) ? null : d
}

export function formatDateTime(raw: string | null | undefined): string {
	const d = toDate(raw)
	if (!d) return raw || '—'
	return d.toLocaleString(undefined, {
		year: 'numeric', month: 'short', day: 'numeric',
		hour: '2-digit', minute: '2-digit',
	})
}

/** Date only, for expiry and subscription bounds. */
export function formatDate(raw: string | null | undefined): string {
	const d = toDate(raw)
	if (!d) return raw || '—'
	return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

/** Time only, for a same-day activity log. */
export function formatTime(raw: string | null | undefined): string {
	const d = toDate(raw)
	if (!d) return '—'
	return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

/** Capitalise a raw server status for display without inventing a mapping. */
export function titleCase(value: string | null | undefined): string {
	if (!value) return '—'
	return value.charAt(0).toUpperCase() + value.slice(1)
}
