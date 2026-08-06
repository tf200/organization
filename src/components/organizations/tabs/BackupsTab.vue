<script setup lang="ts">
/**
 * Backups and rollbacks for one organization.
 *
 * Both lists are `.iz-row--card` expandables, the same shape as the
 * organization rows they are nested inside. What was 700 lines of local
 * structural CSS — `.job-card`, `.status-indicator`, `.progress-track`,
 * `.timeline`, `.rollback-item` — is now the theme's row, pill, badge, meter
 * and inset primitives; only grid tracks, gaps and the one animation the
 * theme has no variant for stay here.
 *
 * Two things the API returns that this never used to show:
 *   - a job's `steps`, which the single-job endpoint has always returned and
 *     which are the honest answer to "how far along is it";
 *   - rollback `steps` and events, whose endpoint was never called at all.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { generateUrl } from '@nextcloud/router'
import ConfirmDialog from '../../ConfirmDialog.vue'
import Pagination from '../../ui/Pagination.vue'
import IzChevron from '../../ui/IzChevron.vue'
import JobSteps from '../../jobs/JobSteps.vue'
import JobEvents from '../../jobs/JobEvents.vue'
import { ocs } from '../../../lib/api'
import { confirmPassword } from '../../../lib/passwordConfirmation'
import { formatDateTime, formatFileSize } from '../../../lib/format'
import { isActive, statusLabel, statusTone } from '../../../lib/jobs'
import type { BackupJob, JobEvent, Organization, RollbackJob } from '../../../types'

const props = defineProps<{ org: Organization }>()

/* Offset paging. Both endpoints answer {jobs, limit, offset} with no total,
   so Pagination infers "there is more" from a full page. Retention keeps only
   the newest 7 finished jobs per organization, so a second page is rare — but
   the previous hardcoded offset 0 made anything past the first page
   unreachable outright. */
const JOBS_PAGE = 20
const ROLLBACK_PAGE = 20
const EVENTS_PAGE = 200

const jobs = ref<BackupJob[]>([])
const jobsOffset = ref(0)
const rollbacks = ref<RollbackJob[]>([])
const rollbackOffset = ref(0)

const loading = ref(true)
const listError = ref('')
const actionError = ref('')

const backupType = ref<'full' | 'incremental'>('full')
const creating = ref(false)
const rollbackBusy = ref(false)
const busyJobId = ref<number | null>(null)

/* The expanded backup, fetched in full because only the single-job endpoint
   populates `steps` — list rows always carry `steps: []`. */
const openJobId = ref<number | null>(null)
const job = ref<BackupJob | null>(null)
const jobEvents = ref<JobEvent[]>([])
const jobPending = ref(false)
const jobError = ref('')

const openRollbackId = ref<number | null>(null)
const rollback = ref<RollbackJob | null>(null)
const rollbackEvents = ref<JobEvent[]>([])
const rollbackPending = ref(false)
const rollbackError = ref('')

const deleteTarget = ref<BackupJob | null>(null)
const deleteError = ref('')

let jobTimer: ReturnType<typeof setInterval> | null = null
let listTimer: ReturnType<typeof setInterval> | null = null

const orgId = computed(() => Number(props.org?.id || 0))

const base = () => `organizations/${orgId.value}/backups`

/**
 * confirmPassword() rejects with the bare string 'cancelled'.
 * @param e
 */
function describe(e: unknown): string {
	if (e === 'cancelled') return 'Password confirmation was cancelled.'
	return e instanceof Error ? e.message : String(e)
}

/* ── Fetching ───────────────────────────────────────────────────────────── */

/**
 * Load the current page of backup jobs.
 */
async function fetchJobs() {
	const data = await ocs<{ jobs: BackupJob[] }>(`${base()}/jobs`, {
		params: { limit: JOBS_PAGE, offset: jobsOffset.value },
	})
	jobs.value = data?.jobs ?? []
	// A page emptied by a delete should step back rather than strand the user
	// on a blank one.
	if (!jobs.value.length && jobsOffset.value > 0) {
		jobsOffset.value = Math.max(0, jobsOffset.value - JOBS_PAGE)
	}
}

/**
 * Load the current page of rollback jobs, and start or stop the list poll to match.
 */
async function fetchRollbacks() {
	const data = await ocs<{ jobs: RollbackJob[] }>(`${base()}/rollback-jobs`, {
		params: { limit: ROLLBACK_PAGE, offset: rollbackOffset.value },
	})
	rollbacks.value = data?.jobs ?? []
	if (!rollbacks.value.length && rollbackOffset.value > 0) {
		rollbackOffset.value = Math.max(0, rollbackOffset.value - ROLLBACK_PAGE)
		return
	}
	// Poll the list only while something is actually moving.
	if (rollbacks.value.some((r) => isActive(r.status))) startListPolling()
	else stopListPolling()
}

/**
 * Fetch one backup job with its steps, plus its event log.
 *
 * Returns the job so callers can decide whether to poll without re-reading
 * the ref, which TypeScript has already narrowed to null by that point.
 * @param jobId the backup job to load
 */
async function loadJobDetail(jobId: number): Promise<BackupJob | null> {
	jobPending.value = true
	jobError.value = ''
	try {
		const [detail, events] = await Promise.all([
			ocs<{ job: BackupJob }>(`${base()}/jobs/${jobId}`),
			ocs<{ events: JobEvent[] }>(`${base()}/jobs/${jobId}/events`, {
				params: { limit: EVENTS_PAGE, offset: 0 },
			}),
		])
		job.value = detail?.job ?? null
		jobEvents.value = events?.events ?? []
		return job.value
	} catch (e) {
		jobError.value = describe(e)
		return null
	} finally {
		jobPending.value = false
	}
}

/**
 * Fetch one rollback job with its steps, plus its event log — an endpoint the UI never called before.
 * @param jobId
 */
async function loadRollbackDetail(jobId: number) {
	rollbackPending.value = true
	rollbackError.value = ''
	try {
		const [detail, events] = await Promise.all([
			ocs<{ job: RollbackJob }>(`${base()}/rollback-jobs/${jobId}`),
			ocs<{ events: JobEvent[] }>(`${base()}/rollback-jobs/${jobId}/events`, {
				params: { limit: EVENTS_PAGE, offset: 0 },
			}),
		])
		rollback.value = detail?.job ?? null
		rollbackEvents.value = events?.events ?? []
	} catch (e) {
		rollbackError.value = describe(e)
	} finally {
		rollbackPending.value = false
	}
}

/* ── Polling ────────────────────────────────────────────────────────────── */

/**
 * False while the tab is in the background, so a tick can be skipped.
 *
 * Both timers skip their tick rather than clearing themselves, and fire one
 * immediate refresh on return — the pattern superadminpage's HandoverPanel and
 * SystemHealthPanel both settled on. A 2s poll that keeps running in a
 * background tab is load with no reader.
 */
function pollable(): boolean {
	return typeof document === 'undefined' || document.visibilityState === 'visible'
}

/**
 * Refresh the open job every 2s until it stops being queued or running.
 * @param jobId
 */
function startJobPolling(jobId: number) {
	stopJobPolling()
	jobTimer = setInterval(async () => {
		if (!pollable()) return
		try {
			const [detail, events] = await Promise.all([
				ocs<{ job: BackupJob }>(`${base()}/jobs/${jobId}`),
				ocs<{ events: JobEvent[] }>(`${base()}/jobs/${jobId}/events`, {
					params: { limit: EVENTS_PAGE, offset: 0 },
				}),
			])
			if (openJobId.value !== jobId) return stopJobPolling()
			if (!detail?.job) return
			job.value = detail.job
			jobEvents.value = events?.events ?? []
			jobError.value = ''
			await fetchJobs()
			if (!isActive(detail.job.status)) stopJobPolling()
		} catch (e) {
			// Say so rather than going quietly still. The previous version
			// swallowed this, so a job whose polling had died looked merely slow.
			stopJobPolling()
			jobError.value = `${describe(e)} Live updates stopped; reopen the job to retry.`
		}
	}, 2000)
}

/**
 * Cancel the open-job poll.
 */
function stopJobPolling() {
	if (jobTimer) {
		clearInterval(jobTimer)
		jobTimer = null
	}
}

/**
 * Refresh the rollback list every 2.5s while any rollback is still moving. Idempotent.
 */
function startListPolling() {
	if (listTimer) return
	listTimer = setInterval(() => {
		if (!pollable()) return
		fetchRollbacks().catch(() => stopListPolling())
	}, 2500)
}

/**
 * Cancel the rollback list poll.
 */
function stopListPolling() {
	if (listTimer) {
		clearInterval(listTimer)
		listTimer = null
	}
}

/**
 * Catch up immediately when the tab comes back to the foreground.
 */
function onVisibility() {
	if (!pollable()) return
	if (openJobId.value !== null && job.value && isActive(job.value.status)) {
		loadJobDetail(openJobId.value)
	}
	if (listTimer) fetchRollbacks().catch(() => stopListPolling())
}

/* ── Expanding ──────────────────────────────────────────────────────────── */

/**
 * Expand or collapse a backup job, loading its detail on open.
 * @param row
 */
async function toggleJob(row: BackupJob) {
	if (openJobId.value === row.jobId) {
		stopJobPolling()
		openJobId.value = null
		job.value = null
		jobEvents.value = []
		return
	}
	stopJobPolling()
	openJobId.value = row.jobId
	job.value = null
	jobEvents.value = []
	const detail = await loadJobDetail(row.jobId)
	if (detail && isActive(detail.status)) startJobPolling(row.jobId)
}

/**
 * Expand or collapse a rollback job, loading its detail on open.
 * @param row
 */
async function toggleRollback(row: RollbackJob) {
	if (openRollbackId.value === row.jobId) {
		openRollbackId.value = null
		rollback.value = null
		rollbackEvents.value = []
		return
	}
	openRollbackId.value = row.jobId
	rollback.value = null
	rollbackEvents.value = []
	await loadRollbackDetail(row.jobId)
}

/* ── Actions ────────────────────────────────────────────────────────────── */

/**
 * Start a backup of the selected type and open it.
 */
async function createJob() {
	creating.value = true
	actionError.value = ''
	try {
		await confirmPassword()
		const data = await ocs<{ job: BackupJob }>(`${base()}/jobs`, {
			method: 'POST',
			form: true,
			body: { backupType: backupType.value },
		})
		jobsOffset.value = 0
		await fetchJobs()
		const created = data?.job
		if (created?.jobId) {
			openJobId.value = created.jobId
			job.value = created
			await loadJobDetail(created.jobId)
			if (isActive(created.status)) startJobPolling(created.jobId)
		}
	} catch (e) {
		// Previously this had no catch at all: a rejected password prompt or a
		// 400 from an invalid backup type surfaced only as an unhandled
		// rejection in the console, and the button just re-enabled.
		actionError.value = describe(e)
	} finally {
		creating.value = false
	}
}

/**
 * Queue a rollback against a completed full backup.
 * @param sourceBackupJobId the backup to restore from
 * @param mode 'dry_run' reports what would change; 'apply' performs it
 */
async function createRollback(sourceBackupJobId: number, mode: 'dry_run' | 'apply') {
	rollbackBusy.value = true
	actionError.value = ''
	try {
		await confirmPassword()
		await ocs(`${base()}/rollback-jobs`, {
			method: 'POST',
			form: true,
			body: { sourceBackupJobId: String(sourceBackupJobId), mode },
		})
		rollbackOffset.value = 0
		await fetchRollbacks()
	} catch (e) {
		actionError.value = describe(e)
	} finally {
		rollbackBusy.value = false
	}
}

/**
 * BackupDownloadController 404s unless the job is completed AND has a
 * non-empty artifactName AND has not expired AND the file still exists.
 * download() navigates the whole page, so that 404 would discard the SPA.
 * The file-exists check cannot be done client-side; the rest can.
 * @param row
 */
function downloadBlockedReason(row: BackupJob): string {
	if (row.status !== 'completed') return ''
	if (!row.artifactName) return 'No archive was produced for this job.'
	if (row.expiresAt) {
		const t = Date.parse(String(row.expiresAt).replace(' ', 'T') + 'Z')
		if (Number.isFinite(t) && t <= Date.now()) {
			return 'The archive expired and was removed after 24 hours.'
		}
	}
	return ''
}

/**
 * Navigate to the archive. Not an XHR: the controller streams the file.
 * @param row the backup whose archive to fetch
 */
async function download(row: BackupJob) {
	actionError.value = ''
	try {
		await confirmPassword()
		window.location.href = generateUrl(
			`/apps/organization/organizations/${orgId.value}/backups/jobs/${row.jobId}/download`)
	} catch (e) {
		actionError.value = describe(e)
	}
}

/**
 * Delete the backup the confirm dialog is pointing at.
 */
async function deleteJob() {
	const target = deleteTarget.value
	if (!target) return
	busyJobId.value = target.jobId
	deleteError.value = ''
	try {
		await confirmPassword()
		await ocs(`${base()}/jobs/${target.jobId}`, { method: 'DELETE' })
		if (openJobId.value === target.jobId) {
			// Without this the 2s timer keeps polling a job that now 404s.
			stopJobPolling()
			openJobId.value = null
			job.value = null
			jobEvents.value = []
		}
		await fetchJobs()
		deleteTarget.value = null
	} catch (e) {
		// The dialog stays open carrying the reason; a failed delete used to
		// look identical to a successful one.
		deleteError.value = describe(e)
	} finally {
		busyJobId.value = null
	}
}

/* ── Derived ────────────────────────────────────────────────────────────── */

/**
     Only a completed full backup can be rolled back — the service rejects
    incrementals outright.
 * @param row
 */
function canRollback(row: BackupJob): boolean {
	return row.status === 'completed' && row.backupType === 'full' && !!row.artifactName
}

/**
 * Apply is gated server-side on a completed dry run that passed validation.
 * @param row
 */
function canApply(row: RollbackJob): boolean {
	return row.mode === 'dry_run' && row.status === 'completed' && row.result?.canApply === true
}

const warnings = computed(() => job.value?.result?.summary?.warnings ?? [])
const counts = computed(() => Object.entries(job.value?.result?.summary?.counts ?? {}))

const validationErrors = computed(() => rollback.value?.result?.validationErrors ?? [])
const rollbackWarnings = computed(() => rollback.value?.result?.warnings ?? [])
const impact = computed(() => Object.entries(rollback.value?.result?.impact ?? {}))
const hasValidation = computed(() =>
	typeof rollback.value?.result?.canApply === 'boolean'
	|| validationErrors.value.length > 0
	|| rollbackWarnings.value.length > 0
	|| impact.value.length > 0)

/**
 * Turn an API key such as `deckBoards` into "Deck boards".
 * @param key
 */
function humanKey(key: string): string {
	const spaced = key.replace(/([A-Z])/g, ' $1').replace(/_/g, ' ').trim()
	return spaced.charAt(0).toUpperCase() + spaced.slice(1)
}

/**
 * Display name for a backup type.
 * @param type
 */
function backupTypeLabel(type: string): string {
	return type === 'incremental' ? 'Incremental' : 'Full'
}

/**
 * Display name for a trigger source.
 * @param source
 */
function triggerLabel(source: string): string {
	return source === 'scheduled' ? 'Scheduled' : 'Manual'
}

/* ── Lifecycle ──────────────────────────────────────────────────────────── */

/**
 * Reset everything and refetch — used on mount and whenever the organization changes.
 */
async function reload() {
	stopJobPolling()
	stopListPolling()
	openJobId.value = null
	openRollbackId.value = null
	job.value = null
	rollback.value = null
	jobEvents.value = []
	rollbackEvents.value = []
	jobs.value = []
	rollbacks.value = []
	listError.value = ''
	actionError.value = ''

	if (!orgId.value) {
		loading.value = false
		return
	}
	loading.value = true
	try {
		await Promise.all([fetchJobs(), fetchRollbacks()])
	} catch (e) {
		listError.value = describe(e)
	} finally {
		loading.value = false
	}
}

watch(orgId, (next, prev) => {
	if (next !== prev) reload()
}, { immediate: true })

/* Changing page collapses the open detail — that job may not be on the new
   page — and stops the timer that was polling it. */
watch(jobsOffset, async () => {
	stopJobPolling()
	openJobId.value = null
	job.value = null
	jobEvents.value = []
	try {
		await fetchJobs()
	} catch (e) {
		listError.value = describe(e)
	}
})

watch(rollbackOffset, async () => {
	openRollbackId.value = null
	rollback.value = null
	rollbackEvents.value = []
	try {
		await fetchRollbacks()
	} catch (e) {
		listError.value = describe(e)
	}
})

document.addEventListener('visibilitychange', onVisibility)

onBeforeUnmount(() => {
	stopJobPolling()
	stopListPolling()
	document.removeEventListener('visibilitychange', onVisibility)
})
</script>

<template>
	<div class="backups">
		<!-- ── Backups ──────────────────────────────────────────────────── -->
		<section class="iz-panel iz-panel--flush">
			<header class="iz-panel__header">
				<h4 class="iz-panel__title">
					Backups
					<span v-if="jobs.length" class="iz-badge iz-badge--muted">{{ jobs.length }}</span>
				</h4>
				<div class="backups__tools">
					<select v-model="backupType"
						class="iz-select backups__select"
						aria-label="Backup type"
						:disabled="creating">
						<option value="full">
							Full backup
						</option>
						<option value="incremental">
							Incremental backup
						</option>
					</select>
					<button class="iz-btn iz-btn--primary iz-btn--sm"
						type="button"
						:disabled="creating"
						@click="createJob">
						<span v-if="creating" class="iz-spinner" />
						{{ creating ? 'Starting…' : 'New backup' }}
					</button>
				</div>
			</header>

			<p class="backups__note">
				Exports shared project files as a ZIP with readable summaries, spreadsheet-friendly tables
				and JSON data. Archives are deleted 24 hours after they are made, and only the seven most
				recent jobs are kept.
			</p>

			<div v-if="actionError" class="iz-error backups__alert" role="alert">
				{{ actionError }}
			</div>

			<div v-if="listError" class="iz-error backups__alert" role="alert">
				{{ listError }}
				<button class="iz-btn iz-btn--accent iz-btn--sm" type="button" @click="reload">
					Try again
				</button>
			</div>

			<p v-if="loading" class="iz-state">
				Loading backup jobs…
			</p>

			<div v-else-if="!jobs.length" class="iz-empty">
				No backups yet. Create one to export every shared project file, with an overview,
				CSV tables, JSON metadata and the full folder structure.
			</div>

			<template v-else>
				<div class="backups__rows">
					<article v-for="row in jobs"
						:key="row.jobId"
						class="iz-row iz-row--card iz-row--expandable"
						:class="{ 'iz-row--expanded': openJobId === row.jobId }">
						<div class="iz-row__header"
							role="button"
							tabindex="0"
							:aria-expanded="openJobId === row.jobId"
							@click="toggleJob(row)"
							@keydown.enter.prevent="toggleJob(row)"
							@keydown.space.prevent="toggleJob(row)">
							<div class="backups__ident">
								<b class="backups__name">Backup #{{ row.jobId }}</b>
								<span class="backups__meta">
									{{ formatDateTime(row.createdAt) }} · {{ triggerLabel(row.triggerSource) }}
									<template v-if="row.artifactSize"> · {{ formatFileSize(row.artifactSize) }}</template>
								</span>
							</div>

							<!-- Indeterminate: the theme's meter is determinate only, and a
							     collapsed row has no steps to measure against. The honest
							     progress readout is the step list in the detail. -->
							<div v-if="isActive(row.status)" class="backups__progress" aria-hidden="true">
								<div class="iz-meter iz-meter--thin iz-meter--indeterminate">
									<div class="iz-meter__fill iz-meter__fill--accent" />
								</div>
							</div>

							<div class="iz-row__actions">
								<span class="iz-badge iz-badge--muted">{{ backupTypeLabel(row.backupType) }}</span>
								<span class="iz-pill" :class="statusTone(row.status)">
									<span class="iz-dot" aria-hidden="true" />{{ statusLabel(row.status) }}
								</span>

								<button v-if="canRollback(row)"
									class="iz-btn iz-btn--sm"
									type="button"
									:disabled="rollbackBusy"
									title="Check what restoring this backup would change, without changing anything"
									@click.stop="createRollback(row.jobId, 'dry_run')">
									Dry-run rollback
								</button>

								<button v-if="row.status === 'completed'"
									class="iz-btn iz-btn--primary iz-btn--sm"
									type="button"
									:title="downloadBlockedReason(row) || 'Download the archive'"
									:disabled="!!downloadBlockedReason(row)"
									@click.stop="download(row)">
									Download
								</button>

								<button class="iz-btn iz-btn--icon iz-btn--sm"
									type="button"
									:disabled="busyJobId === row.jobId"
									:title="`Delete backup #${row.jobId}`"
									:aria-label="`Delete backup #${row.jobId}`"
									@click.stop="deleteTarget = row">
									<span v-if="busyJobId === row.jobId" class="iz-spinner" />
									<template v-else>
										&times;
									</template>
								</button>

								<IzChevron :open="openJobId === row.jobId" />
							</div>
						</div>

						<div v-if="openJobId === row.jobId" class="iz-row__detail backups__detail">
							<p v-if="jobPending && !job" class="iz-state">
								Loading job detail…
							</p>
							<!-- Shown alongside the detail, not instead of it: a failed poll
							     must not wipe the panel the user is reading. -->
							<div v-if="jobError" class="iz-error" role="alert">
								{{ jobError }}
							</div>

							<template v-if="job">
								<div v-if="job.errorMessage" class="iz-error" role="alert">
									{{ job.errorMessage }}
								</div>

								<div v-if="warnings.length" class="iz-inset">
									<span class="iz-section-title">Warnings</span>
									<ul class="backups__bullets">
										<li v-for="(text, i) in warnings" :key="i">
											{{ text }}
										</li>
									</ul>
								</div>

								<dl class="iz-kv">
									<div class="iz-kv__item">
										<dt class="iz-kv__label">
											Type
										</dt>
										<dd class="iz-kv__value">
											{{ backupTypeLabel(job.backupType) }}
											<template v-if="job.baseFullJobId">
												· based on #{{ job.baseFullJobId }}
											</template>
										</dd>
									</div>
									<div class="iz-kv__item">
										<dt class="iz-kv__label">
											Trigger
										</dt>
										<dd class="iz-kv__value">
											{{ triggerLabel(job.triggerSource) }}
										</dd>
									</div>
									<div class="iz-kv__item">
										<dt class="iz-kv__label">
											Created
										</dt>
										<dd class="iz-kv__value">
											{{ formatDateTime(job.createdAt) }}
										</dd>
									</div>
									<div class="iz-kv__item">
										<dt class="iz-kv__label">
											Finished
										</dt>
										<dd class="iz-kv__value">
											{{ formatDateTime(job.finishedAt) }}
										</dd>
									</div>
									<div class="iz-kv__item">
										<dt class="iz-kv__label">
											Expires
										</dt>
										<dd class="iz-kv__value">
											{{ formatDateTime(job.expiresAt) }}
										</dd>
									</div>
									<div v-if="job.artifactSize" class="iz-kv__item">
										<dt class="iz-kv__label">
											Archive size
										</dt>
										<dd class="iz-kv__value">
											{{ formatFileSize(job.artifactSize) }}
										</dd>
									</div>
									<div v-if="job.artifactName" class="iz-kv__item iz-kv__item--wide">
										<dt class="iz-kv__label">
											Archive
										</dt>
										<dd class="iz-kv__value iz-kv__value--mono">
											{{ job.artifactName }}
										</dd>
									</div>
									<div class="iz-kv__item">
										<dt class="iz-kv__label">
											Requested by
										</dt>
										<dd class="iz-kv__value">
											{{ job.requestedByUid === '__system__' ? 'Scheduler' : job.requestedByUid }}
										</dd>
									</div>
								</dl>

								<section v-if="counts.length" class="backups__section">
									<span class="iz-section-title">Contents</span>
									<div class="backups__chips">
										<span v-for="[key, value] in counts" :key="key" class="iz-badge iz-badge--muted">
											{{ humanKey(key) }}: {{ value }}
										</span>
									</div>
								</section>

								<!-- Steps have always been returned by this endpoint and were
								     never rendered, so a running job showed only an animation. -->
								<section v-if="job.steps.length" class="backups__section">
									<span class="iz-section-title">Steps</span>
									<JobSteps :steps="job.steps" />
								</section>

								<section class="backups__section">
									<span class="iz-section-title">
										Activity log
										<span v-if="jobEvents.length" class="iz-badge iz-badge--muted">{{ jobEvents.length }}</span>
									</span>
									<JobEvents :events="jobEvents" :loading="jobPending" />
								</section>
							</template>
						</div>
					</article>
				</div>

				<Pagination v-model:offset="jobsOffset"
					:limit="JOBS_PAGE"
					:count="jobs.length"
					label="backups" />
			</template>
		</section>

		<!-- ── Rollbacks ────────────────────────────────────────────────── -->
		<section class="iz-panel iz-panel--flush">
			<header class="iz-panel__header">
				<h4 class="iz-panel__title">
					Rollback jobs
					<span v-if="rollbacks.length" class="iz-badge iz-badge--muted">{{ rollbacks.length }}</span>
				</h4>
			</header>

			<div v-if="!rollbacks.length" class="iz-empty">
				No rollback jobs yet. Start one with “Dry-run rollback” on a completed full backup —
				it reports what restoring would change without changing anything.
			</div>

			<template v-else>
				<div class="backups__rows">
					<article v-for="row in rollbacks"
						:key="row.jobId"
						class="iz-row iz-row--card iz-row--expandable"
						:class="{ 'iz-row--expanded': openRollbackId === row.jobId }">
						<div class="iz-row__header"
							role="button"
							tabindex="0"
							:aria-expanded="openRollbackId === row.jobId"
							@click="toggleRollback(row)"
							@keydown.enter.prevent="toggleRollback(row)"
							@keydown.space.prevent="toggleRollback(row)">
							<div class="backups__ident">
								<b class="backups__name">Rollback #{{ row.jobId }}</b>
								<span class="backups__meta">
									From backup #{{ row.sourceBackupJobId }} · {{ formatDateTime(row.createdAt) }}
								</span>
							</div>

							<div v-if="isActive(row.status)" class="backups__progress" aria-hidden="true">
								<div class="iz-meter iz-meter--thin iz-meter--indeterminate">
									<div class="iz-meter__fill iz-meter__fill--accent" />
								</div>
							</div>

							<div class="iz-row__actions">
								<span class="iz-badge" :class="row.mode === 'apply' ? 'iz-badge--warning' : 'iz-badge--muted'">
									{{ row.mode === 'apply' ? 'Apply' : 'Dry run' }}
								</span>
								<span class="iz-pill" :class="statusTone(row.status)">
									<span class="iz-dot" aria-hidden="true" />{{ statusLabel(row.status) }}
								</span>
								<button v-if="canApply(row)"
									class="iz-btn iz-btn--primary iz-btn--sm"
									type="button"
									:disabled="rollbackBusy"
									@click.stop="createRollback(row.sourceBackupJobId, 'apply')">
									Apply rollback
								</button>
								<IzChevron :open="openRollbackId === row.jobId" />
							</div>
						</div>

						<div v-if="openRollbackId === row.jobId" class="iz-row__detail backups__detail">
							<p v-if="rollbackPending && !rollback" class="iz-state">
								Loading rollback detail…
							</p>
							<div v-if="rollbackError" class="iz-error" role="alert">
								{{ rollbackError }}
							</div>

							<template v-if="rollback">
								<div v-if="rollback.errorMessage" class="iz-error" role="alert">
									{{ rollback.errorMessage }}
								</div>

								<div v-if="hasValidation" class="iz-inset backups__validation">
									<span class="iz-pill"
										:class="rollback.result?.canApply ? 'iz-pill--success' : 'iz-pill--danger'">
										{{ rollback.result?.canApply ? 'Validation passed' : 'Validation blocked' }}
									</span>

									<div v-if="validationErrors.length">
										<span class="iz-section-title">Validation errors</span>
										<ul class="backups__bullets">
											<li v-for="(text, i) in validationErrors" :key="i">
												{{ text }}
											</li>
										</ul>
									</div>

									<div v-if="rollbackWarnings.length">
										<span class="iz-section-title">Warnings</span>
										<ul class="backups__bullets">
											<li v-for="(text, i) in rollbackWarnings" :key="i">
												{{ text }}
											</li>
										</ul>
									</div>

									<div v-if="impact.length" class="backups__chips">
										<span v-for="[key, value] in impact" :key="key" class="iz-badge iz-badge--muted">
											{{ humanKey(key) }}: {{ value }}
										</span>
									</div>
								</div>

								<dl class="iz-kv">
									<div class="iz-kv__item">
										<dt class="iz-kv__label">
											Mode
										</dt>
										<dd class="iz-kv__value">
											{{ rollback.mode === 'apply' ? 'Apply' : 'Dry run' }}
										</dd>
									</div>
									<div class="iz-kv__item">
										<dt class="iz-kv__label">
											Source backup
										</dt>
										<dd class="iz-kv__value">
											#{{ rollback.sourceBackupJobId }}
										</dd>
									</div>
									<div class="iz-kv__item">
										<dt class="iz-kv__label">
											Created
										</dt>
										<dd class="iz-kv__value">
											{{ formatDateTime(rollback.createdAt) }}
										</dd>
									</div>
									<div class="iz-kv__item">
										<dt class="iz-kv__label">
											Finished
										</dt>
										<dd class="iz-kv__value">
											{{ formatDateTime(rollback.finishedAt) }}
										</dd>
									</div>
									<div v-if="rollback.preRestoreBackupJobId" class="iz-kv__item">
										<dt class="iz-kv__label">
											Safety snapshot
										</dt>
										<dd class="iz-kv__value">
											Backup #{{ rollback.preRestoreBackupJobId }}
										</dd>
									</div>
									<div class="iz-kv__item">
										<dt class="iz-kv__label">
											Requested by
										</dt>
										<dd class="iz-kv__value">
											{{ rollback.requestedByUid }}
										</dd>
									</div>
								</dl>

								<section v-if="rollback.steps.length" class="backups__section">
									<span class="iz-section-title">Steps</span>
									<JobSteps :steps="rollback.steps" />
								</section>

								<section class="backups__section">
									<span class="iz-section-title">
										Activity log
										<span v-if="rollbackEvents.length" class="iz-badge iz-badge--muted">{{ rollbackEvents.length }}</span>
									</span>
									<JobEvents :events="rollbackEvents" :loading="rollbackPending" />
								</section>
							</template>
						</div>
					</article>
				</div>

				<Pagination v-model:offset="rollbackOffset"
					:limit="ROLLBACK_PAGE"
					:count="rollbacks.length"
					label="rollback jobs" />
			</template>
		</section>

		<ConfirmDialog v-if="deleteTarget"
			:title="`Delete backup #${deleteTarget.jobId}?`"
			message="The archive is removed permanently. This cannot be undone."
			confirm-label="Delete backup"
			busy-label="Deleting…"
			danger
			:busy="busyJobId === deleteTarget.jobId"
			:error="deleteError"
			@confirm="deleteJob"
			@cancel="deleteTarget = null; deleteError = ''" />
	</div>
</template>

<style scoped>
/* Layout only. Every surface, border, radius, shadow, type size and colour
   comes from an .iz-* primitive — see the class list in the template.
   The one exception is the indeterminate pulse at the bottom: the theme's
   meter is determinate only and ships a single keyframes rule (iz-spin). */
.backups {
	display: flex;
	flex-direction: column;
	gap: var(--iz-gap);
}

.backups__tools {
	display: flex;
	align-items: center;
	gap: 8px;
}

/* .iz-select is width:100% in the theme — right for a stacked field, wrong in
   a header row. Qualified on the toolbar to reach (0,3,0): this app's CSS is a
   linked file that Nextcloud loads BEFORE the theme, so on a tie the theme
   wins here, not the app. */
.backups__tools .backups__select {
	width: auto;
	min-width: 170px;
}

.backups__note {
	margin: 0 0 var(--iz-gap);
	font-size: var(--iz-fs-md);
	color: var(--iz-text-secondary);
	line-height: 1.45;
}

.backups__alert {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: var(--iz-gap);
}

.backups__rows {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.backups__ident {
	display: flex;
	flex-direction: column;
	gap: 1px;
	min-width: 0;
	flex: 1;
}

.backups__name {
	font-size: var(--iz-fs-md);
	font-weight: 600;
	white-space: nowrap;
}

.backups__meta {
	font-size: var(--iz-fs-xs);
	color: var(--iz-text-muted);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.backups__progress {
	width: 90px;
	flex-shrink: 0;
}

.backups__detail {
	display: flex;
	flex-direction: column;
	gap: var(--iz-gap);
}

.backups__section {
	display: flex;
	flex-direction: column;
}

.backups__bullets {
	margin: 0;
	padding-left: 18px;
	font-size: var(--iz-fs-md);
	color: var(--iz-text-secondary);
}

.backups__chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.backups__validation {
	display: flex;
	flex-direction: column;
	gap: 10px;
	align-items: flex-start;
}

@media (max-width: 700px) {
	.backups__progress { display: none; }

	.backups__meta { white-space: normal; }
}
</style>
