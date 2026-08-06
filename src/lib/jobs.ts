/**
 * Shared vocabulary for the three async job kinds — backup, rollback and
 * account handover.
 *
 * They are separate tables and separate services, but the API emits the same
 * status strings, the same step record and the same event record for all
 * three, so the display rules belong in one place rather than in a private
 * copy per tab. BackupsTab and HandoverTab each had their own STATUS_TONE
 * table and they had already drifted: HandoverTab listed `skipped` as a *job*
 * status, which no job ever has, and neither listed `expired` or `deleted`,
 * which a backup job routinely does.
 */

/**
 * Never build a class name from data — `'iz-pill--' + status` silently emits a
 * class that may not exist, and the element then renders unstyled. Explicit
 * table, neutral fallback.
 *
 * Covers every value the server can emit: backup jobs add `expired` and
 * `deleted`, steps add `skipped`.
 */
const STATUS_TONE: Record<string, string> = {
	queued: 'iz-pill--muted',
	running: 'iz-pill--accent',
	completed: 'iz-pill--success',
	failed: 'iz-pill--danger',
	expired: 'iz-pill--warning',
	deleted: 'iz-pill--muted',
	skipped: 'iz-pill--muted',
}

/**
 * Pill or badge tone for a job or step status.
 * @param status raw status string from the API
 */
export function statusTone(status: string | null | undefined): string {
	return STATUS_TONE[status ?? ''] ?? 'iz-pill--muted'
}

/**
 * `.iz-pill` already capitalises, so this only has to undo the wire format.
 * Without it `dry_run` renders as "Dry_run".
 * @param status raw status string from the API
 */
export function statusLabel(status: string | null | undefined): string {
	if (!status) return 'Unknown'
	return status.replace(/_/g, ' ')
}

/**
 * A job is still moving, so it is worth polling.
 * @param status raw status string from the API
 */
export function isActive(status: string | null | undefined): boolean {
	return status === 'queued' || status === 'running'
}

/**
 * Human names for every step key the three services define.
 *
 * The keys do not collide across job kinds, so one table serves all three.
 * `finalize` is genuinely shared and means the same thing in each.
 *
 * Source of truth is each service's STEP_ORDER constant:
 *   backup   collect_db, export_deck, export_files, finalize
 *   rollback validate_source, dry_run_preview, snapshot_pre_restore,
 *            restore_db, restore_files, finalize
 *   handover projectcreator, deck, finalize
 */
const STEP_NAMES: Record<string, string> = {
	// backup
	collect_db: 'Collect database records',
	export_deck: 'Export Deck boards',
	export_files: 'Export shared files',
	// rollback
	validate_source: 'Validate source backup',
	dry_run_preview: 'Preview changes',
	snapshot_pre_restore: 'Snapshot before restore',
	restore_db: 'Restore database records',
	restore_files: 'Restore files',
	// handover
	projectcreator: 'Transfer project ownership',
	deck: 'Remap Deck content',
	// shared
	finalize: 'Finalize',
}

/**
 * Falls back to a humanised key rather than hiding an unknown step: a new
 * step key added server-side should still read as something.
 * @param key the step's stepKey as emitted by the service
 */
export function stepName(key: string | null | undefined): string {
	if (!key) return 'Unknown step'
	const known = STEP_NAMES[key]
	if (known) return known
	const spaced = key.replace(/_/g, ' ')
	return spaced.charAt(0).toUpperCase() + spaced.slice(1)
}

/** Event levels are `info` | `warning` | `error`; the column is unconstrained. */
const EVENT_TONE: Record<string, string> = {
	info: 'iz-badge--muted',
	warning: 'iz-badge--warning',
	error: 'iz-badge--danger',
}

/**
 * Badge tone for an event's level.
 * @param level 'info', 'warning' or 'error'
 */
export function eventTone(level: string | null | undefined): string {
	return EVENT_TONE[level ?? ''] ?? 'iz-badge--muted'
}
