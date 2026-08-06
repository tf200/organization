<template>
	<div class="backup-section">
		<!-- Header Area -->
		<div class="backup-intro">
			<div class="intro-content">
				<div class="intro-icon-wrap">
					<CloudDownload :size="28" />
				</div>
				<div class="intro-text">
					<h3 class="intro-title">
						Organization Backup
					</h3>
					<p class="intro-desc">
						Export shared project files as a ZIP archive with readable summaries, spreadsheet-friendly tables,
						and JSON data. Backups expire automatically after 24 hours.
					</p>
				</div>
			</div>
			<div class="backup-actions">
				<div class="backup-type-picker">
					<label for="backup-type-select">Backup Type</label>
					<select id="backup-type-select" v-model="selectedBackupType" :disabled="creating">
						<option value="full">
							Full
						</option>
						<option value="incremental">
							Incremental
						</option>
					</select>
				</div>
				<IzButton type="primary"
					:disabled="creating"
					@click="createJob">
					<template #icon>
						<IzSpinner v-if="creating" :size="20" />
						<Plus v-else :size="20" />
					</template>
					New Backup
				</IzButton>
			</div>
		</div>

		<!-- Loading State -->
		<div v-if="initialLoading" class="state-card">
			<IzSpinner :size="32" />
			<p class="state-text">
				Loading backup jobs…
			</p>
		</div>

		<!-- Empty State -->
		<div v-else-if="jobs.length === 0" class="state-card empty-state">
			<div class="empty-icon-wrap">
				<DatabaseOff :size="40" />
			</div>
			<h4 class="empty-title">
				No backups yet
			</h4>
			<p class="empty-desc">
				Create your first backup to export all shared project files.
				Backups include readable overviews, CSV tables, JSON metadata, and the full folder structure.
			</p>
			<IzButton type="primary"
				:disabled="creating"
				@click="createJob">
				<template #icon>
					<IzSpinner v-if="creating" :size="20" />
					<Plus v-else :size="20" />
				</template>
				Create First Backup
			</IzButton>
		</div>

		<!-- Jobs List -->
		<template v-else>
			<TransitionGroup name="job-list" tag="div" class="jobs-list">
				<div v-for="job in jobs"
					:key="job.jobId"
					class="job-card"
					:class="{ expanded: selectedJob?.jobId === job.jobId }"
					@click="toggleJob(job)">
					<!-- Job Summary Row -->
					<div class="job-summary">
						<div class="job-left">
							<!-- Status Indicator -->
							<div class="status-indicator" :class="job.status">
								<IzSpinner v-if="isActiveStatus(job.status)" :size="18" />
								<Check v-else-if="job.status === 'completed'" :size="18" />
								<AlertCircle v-else-if="job.status === 'failed'" :size="18" />
								<ClockOutline v-else :size="18" />
							</div>

							<div class="job-info">
								<div class="job-name-row">
									<span class="job-name">Backup #{{ job.jobId }}</span>
									<span class="iz-badge iz-badge--muted">{{ formatBackupType(job.backupType) }}</span>
									<span class="iz-pill" :class="statusTone(job.status)">
										{{ formatStatus(job.status) }}
									</span>
								</div>
								<div class="job-timestamps">
									<span class="timestamp">
										<CalendarClock :size="13" />
										{{ formatDate(job.createdAt) }}
									</span>
									<span v-if="job.expiresAt" class="timestamp expires">
										<TimerSand :size="13" />
										Expires {{ formatDate(job.expiresAt) }}
									</span>
								</div>
							</div>
						</div>

						<div class="job-right">
							<IzButton v-if="job.status === 'completed' && job.backupType === 'full'"
								type="secondary"
								:disabled="creatingRollback"
								@click.stop="createRollbackJob(job.jobId, 'dry_run')">
								Dry-run Rollback
							</IzButton>
							<IzButton v-if="job.status === 'completed'"
								type="primary"
								:title="downloadBlockedReason(job) || 'Download the archive'"
								:disabled="actionLoading === job.jobId || !!downloadBlockedReason(job)"
								@click.stop="download(job)">
								<template #icon>
									<Download :size="18" />
								</template>
								Download
							</IzButton>
							<IzButton type="error"
								:disabled="actionLoading === job.jobId"
								@click.stop="confirmDelete(job)">
								<template #icon>
									<IzSpinner v-if="actionLoading === job.jobId" :size="18" />
									<Delete v-else :size="18" />
								</template>
							</IzButton>
							<ChevronDown :size="20"
								class="expand-icon"
								:class="{ rotated: selectedJob?.jobId === job.jobId }" />
						</div>
					</div>

					<!-- Error Banner -->
					<div v-if="job.errorMessage" class="error-banner" @click.stop>
						<AlertCircle :size="16" />
						<span>{{ job.errorMessage }}</span>
					</div>

					<!-- Progress Bar for Active Jobs -->
					<div v-if="isActiveStatus(job.status)" class="progress-track" @click.stop>
						<div class="progress-fill" :class="job.status" />
					</div>

					<!-- Expanded Detail Panel -->
					<Transition name="expand">
						<div v-if="selectedJob?.jobId === job.jobId"
							class="job-detail"
							@click.stop>
							<!-- Detail Grid -->
							<div class="detail-grid">
								<div class="detail-item">
									<span class="detail-label">Job ID</span>
									<span class="detail-value mono">{{ selectedJob.jobId }}</span>
								</div>
								<div class="detail-item">
									<span class="detail-label">Status</span>
									<span class="detail-value capitalize">{{ selectedJob.status }}</span>
								</div>
								<div class="detail-item">
									<span class="detail-label">Created</span>
									<span class="detail-value">{{ formatDate(selectedJob.createdAt) }}</span>
								</div>
								<div class="detail-item">
									<!-- Was bound to completedAt, which mapJobRow never returns, so this
										     row rendered an em-dash for every job ever. -->
									<span class="detail-label">Finished</span>
									<span class="detail-value">{{ formatDate(selectedJob.finishedAt) }}</span>
								</div>
								<div v-if="selectedJob.expiresAt" class="detail-item">
									<span class="detail-label">Expires</span>
									<span class="detail-value">{{ formatDate(selectedJob.expiresAt) }}</span>
								</div>
								<div v-if="selectedJob.artifactSize" class="detail-item">
									<!-- Was bound to fileSize, also never returned, so this row never
										     rendered at all. -->
									<span class="detail-label">Artifact size</span>
									<span class="detail-value">{{ formatFileSize(selectedJob.artifactSize) }}</span>
								</div>
								<div v-if="selectedJob.artifactName" class="detail-item">
									<span class="detail-label">Artifact</span>
									<span class="detail-value mono">{{ selectedJob.artifactName }}</span>
								</div>
							</div>

							<!-- Events Timeline -->
							<div class="events-section">
								<h4 class="events-title">
									<TimelineText :size="18" />
									Activity Log
									<span v-if="events.length" class="events-count">{{ events.length }}</span>
								</h4>

								<div v-if="events.length === 0" class="events-empty">
									<span>No activity recorded yet.</span>
								</div>

								<div v-else class="timeline">
									<div v-for="(evt, idx) in events"
										:key="evt.id"
										class="timeline-item"
										:class="evt.level">
										<div class="timeline-connector">
											<div class="timeline-dot" :class="evt.level" />
											<div v-if="idx < events.length - 1" class="timeline-line" />
										</div>
										<div class="timeline-content">
											<div class="timeline-header">
												<span class="event-level-badge" :class="evt.level">
													{{ evt.level }}
												</span>
												<span class="event-time">{{ formatDate(evt.createdAt) }}</span>
											</div>
											<p class="event-message">
												{{ evt.message }}
											</p>
										</div>
									</div>
								</div>
							</div>
						</div>
					</Transition>
				</div>
			</TransitionGroup>
		</template>

		<div class="rollback-panel">
			<div class="rollback-header">
				<h4>Rollback Jobs</h4>
			</div>
			<div v-if="rollbackJobs.length === 0" class="rollback-empty">
				No rollback jobs yet.
			</div>
			<div v-else class="rollback-list">
				<div v-for="job in rollbackJobs" :key="job.jobId" class="rollback-item">
					<div class="rollback-main">
						<div class="rollback-row">
							<span class="rollback-name">Rollback #{{ job.jobId }}</span>
							<span class="iz-pill" :class="statusTone(job.status)">{{ formatStatus(job.status) }}</span>
						</div>
						<div class="rollback-meta">
							<span>Mode: {{ job.mode === 'apply' ? 'Apply' : 'Dry-run' }}</span>
							<span>Source backup: #{{ job.sourceBackupJobId }}</span>
							<span>{{ formatDate(job.createdAt) }}</span>
						</div>
						<div v-if="job.errorMessage" class="rollback-error">
							{{ job.errorMessage }}
						</div>
						<div v-if="hasRollbackValidationSummary(job)" class="rollback-validation">
							<div class="rollback-validation-status" :class="{ blocked: job.result?.canApply === false, ready: job.result?.canApply === true }">
								{{ job.result?.canApply === true ? 'Validation passed' : 'Validation blocked' }}
							</div>
							<div v-if="rollbackValidationErrors(job).length" class="rollback-validation-section">
								<div class="rollback-validation-label">
									Validation errors
								</div>
								<ul class="rollback-validation-list">
									<li v-for="message in rollbackValidationErrors(job)" :key="message">
										{{ message }}
									</li>
								</ul>
							</div>
							<div v-if="rollbackWarnings(job).length" class="rollback-validation-section">
								<div class="rollback-validation-label">
									Warnings
								</div>
								<ul class="rollback-validation-list warnings">
									<li v-for="message in rollbackWarnings(job)" :key="message">
										{{ message }}
									</li>
								</ul>
							</div>
							<div v-if="rollbackImpactEntries(job).length" class="rollback-impact">
								<span v-for="[key, value] in rollbackImpactEntries(job)" :key="key" class="rollback-impact-chip">
									{{ formatImpactKey(key) }}: {{ value }}
								</span>
							</div>
						</div>
					</div>
					<div class="rollback-actions">
						<IzButton v-if="job.mode === 'dry_run' && job.status === 'completed' && job.result?.canApply === true"
							type="primary"
							:disabled="creatingRollback"
							@click="createRollbackJob(job.sourceBackupJobId, 'apply')">
							Apply Rollback
						</IzButton>
					</div>
				</div>
			</div>
		</div>

		<ConfirmDialog v-if="deleteTarget"
			:title="'Delete Backup #' + deleteTarget.jobId"
			message="This cannot be undone. The archive is removed permanently."
			confirm-label="Delete backup"
			busy-label="Deleting…"
			danger
			:busy="actionLoading === deleteTarget.jobId"
			:error="deleteError"
			@confirm="deleteJob"
			@cancel="deleteTarget = null; deleteError = ''" />
	</div>
</template>

<script setup lang="ts">
import ConfirmDialog from '../../ConfirmDialog.vue'
import IzButton from '../../ui/IzButton.vue'
import IzSpinner from '../../ui/IzSpinner.vue'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { confirmPassword } from '../../../lib/passwordConfirmation'

import Download from 'vue-material-design-icons/Download.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Check from 'vue-material-design-icons/Check.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import TimerSand from 'vue-material-design-icons/TimerSand.vue'
import CloudDownload from 'vue-material-design-icons/CloudDownload.vue'
import DatabaseOff from 'vue-material-design-icons/DatabaseOff.vue'
import TimelineText from 'vue-material-design-icons/TimelineText.vue'

const props = defineProps<{
	organization: any
}>()

type RollbackResult = {
	canApply?: boolean
	validationErrors?: unknown
	warnings?: unknown
	impact?: unknown
}

type RollbackJobSummary = {
	result?: RollbackResult | null
}

const initialLoading = ref(true)
const creating = ref(false)
const creatingRollback = ref(false)
const actionLoading = ref<number | null>(null)
const jobs = ref<any[]>([])
const rollbackJobs = ref<any[]>([])
const selectedJob = ref<any | null>(null)
const events = ref<any[]>([])
const deleteTarget = ref<any | null>(null)
const deleteError = ref('')
const selectedBackupType = ref<'full' | 'incremental'>('full')

let pollTimer: ReturnType<typeof setInterval> | null = null
let rollbackPollTimer: ReturnType<typeof setInterval> | null = null

const organizationId = computed(() => Number(props.organization?.id || 0))

const jobsUrl = () => generateOcsUrl(`apps/organization/organizations/${props.organization.id}/backups/jobs`)
const jobUrl = (jobId: number) => generateOcsUrl(`apps/organization/organizations/${props.organization.id}/backups/jobs/${jobId}`)
const eventsUrl = (jobId: number) => generateOcsUrl(`apps/organization/organizations/${props.organization.id}/backups/jobs/${jobId}/events`)
const rollbackJobsUrl = () => generateOcsUrl(`apps/organization/organizations/${props.organization.id}/backups/rollback-jobs`)
const downloadUrl = (jobId: number) => generateUrl(`/apps/organization/organizations/${props.organization.id}/backups/jobs/${jobId}/download`)

function isActiveStatus(status: string): boolean {
	return ['queued', 'running'].includes(status)
}

/**
 * Explicit table, neutral fallback — never build a class from data. The old
 * `.status-badge` + :class="job.status" pattern also emitted classes for
 * statuses that have no rule (expired, deleted), which rendered unstyled.
 */
const STATUS_TONE: Record<string, string> = {
	queued: 'iz-pill--muted',
	running: 'iz-pill--accent',
	completed: 'iz-pill--success',
	failed: 'iz-pill--danger',
	expired: 'iz-pill--warning',
	deleted: 'iz-pill--muted',
}

function statusTone(status: string): string {
	return STATUS_TONE[status] ?? 'iz-pill--muted'
}

function formatStatus(status: string): string {
	const map: Record<string, string> = {
		queued: 'Queued',
		running: 'Running',
		completed: 'Completed',
		failed: 'Failed',
	}
	return map[status] ?? status
}

function formatBackupType(type: string | null | undefined): string {
	return type === 'incremental' ? 'Incremental' : 'Full'
}

function formatDate(raw: string | null): string {
	if (!raw) return '—'
	try {
		const d = new Date(raw)
		if (isNaN(d.getTime())) return raw
		return d.toLocaleString(undefined, {
			month: 'short',
			day: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
		})
	} catch {
		return raw
	}
}

function formatFileSize(bytes: number): string {
	if (!bytes || bytes === 0) return '0 B'
	const k = 1024
	const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
	const i = Math.floor(Math.log(bytes) / Math.log(k))
	return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

function rollbackValidationErrors(job: RollbackJobSummary): string[] {
	return Array.isArray(job?.result?.validationErrors) ? job.result.validationErrors.filter((value: unknown): value is string => typeof value === 'string' && value.trim().length > 0) : []
}

function rollbackWarnings(job: RollbackJobSummary): string[] {
	return Array.isArray(job?.result?.warnings) ? job.result.warnings.filter((value: unknown): value is string => typeof value === 'string' && value.trim().length > 0) : []
}

function rollbackImpactEntries(job: RollbackJobSummary): Array<[string, string | number]> {
	const impact = job?.result?.impact
	if (!impact || typeof impact !== 'object' || Array.isArray(impact)) {
		return []
	}

	return Object.entries(impact)
		.filter(([, value]) => typeof value === 'number' || typeof value === 'string')
		.map(([key, value]) => [key, value as string | number])
}

function hasRollbackValidationSummary(job: RollbackJobSummary): boolean {
	return typeof job?.result?.canApply === 'boolean'
		|| rollbackValidationErrors(job).length > 0
		|| rollbackWarnings(job).length > 0
		|| rollbackImpactEntries(job).length > 0
}

function formatImpactKey(key: string): string {
	const withSpaces = key.replace(/([A-Z])/g, ' $1').replace(/_/g, ' ').trim()
	return withSpaces.charAt(0).toUpperCase() + withSpaces.slice(1)
}

async function fetchJobs() {
	const { data } = await axios.get(jobsUrl(), { params: { limit: 20, offset: 0 } })
	jobs.value = data?.ocs?.data?.jobs ?? []
}

async function fetchRollbackJobs() {
	const { data } = await axios.get(rollbackJobsUrl(), { params: { limit: 30, offset: 0 } })
	rollbackJobs.value = data?.ocs?.data?.jobs ?? []

	const hasActive = rollbackJobs.value.some((job) => isActiveStatus(job.status))
	if (hasActive) {
		startRollbackPolling()
	} else {
		stopRollbackPolling()
	}
}

async function fetchJob(jobId: number) {
	const { data } = await axios.get(jobUrl(jobId))
	return data?.ocs?.data?.job ?? null
}

async function fetchEvents(jobId: number) {
	const { data } = await axios.get(eventsUrl(jobId), { params: { limit: 200, offset: 0 } })
	events.value = data?.ocs?.data?.events ?? []
}

function startPolling(jobId: number) {
	stopPolling()
	pollTimer = setInterval(async () => {
		try {
			const job = await fetchJob(jobId)
			if (!job) return
			selectedJob.value = job
			await fetchJobs()
			await fetchEvents(jobId)

			if (!isActiveStatus(job.status)) {
				stopPolling()
			}
		} catch {
			// Silently ignore polling errors
		}
	}, 2000)
}

function stopPolling() {
	if (pollTimer) {
		clearInterval(pollTimer)
		pollTimer = null
	}
}

function startRollbackPolling() {
	if (rollbackPollTimer) {
		return
	}
	rollbackPollTimer = setInterval(async () => {
		try {
			await fetchRollbackJobs()
		} catch {
			// Silently ignore polling errors
		}
	}, 2500)
}

function stopRollbackPolling() {
	if (rollbackPollTimer) {
		clearInterval(rollbackPollTimer)
		rollbackPollTimer = null
	}
}

async function createJob() {
	creating.value = true
	try {
		await confirmPassword()
		const body = new URLSearchParams()
		body.set('backupType', selectedBackupType.value)
		const { data } = await axios.post(jobsUrl(), body, {
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
		})
		const job = data?.ocs?.data?.job
		await fetchJobs()
		if (job?.jobId) {
			selectedJob.value = job
			await fetchEvents(job.jobId)
			startPolling(job.jobId)
		}
	} finally {
		creating.value = false
	}
}

async function createRollbackJob(sourceBackupJobId: number, mode: 'dry_run' | 'apply') {
	creatingRollback.value = true
	try {
		await confirmPassword()
		const body = new URLSearchParams()
		body.set('sourceBackupJobId', String(sourceBackupJobId))
		body.set('mode', mode)
		await axios.post(rollbackJobsUrl(), body, {
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
		})
		await fetchRollbackJobs()
	} finally {
		creatingRollback.value = false
	}
}

async function toggleJob(job: any) {
	if (selectedJob.value?.jobId === job.jobId) {
		selectedJob.value = null
		events.value = []
		stopPolling()
		return
	}
	selectedJob.value = await fetchJob(job.jobId)
	await fetchEvents(job.jobId)
	if (selectedJob.value?.status && isActiveStatus(selectedJob.value.status)) {
		startPolling(job.jobId)
	}
}

/**
 * BackupDownloadController 404s unless the job is completed AND has a
 * non-empty artifactName AND has not expired AND the file still exists.
 * The UI only checked status, and because download() navigates the whole
 * page via window.location.href, that 404 discarded all component state.
 * The file-exists check cannot be done client-side; the rest can.
 * @param job
 */
function downloadBlockedReason(job: any): string {
	if (!job || job.status !== 'completed') return ''
	if (!job.artifactName) return 'No archive was produced for this job.'
	const raw = job.expiresAt
	if (raw) {
		const t = Date.parse(String(raw).replace(' ', 'T') + 'Z')
		if (Number.isFinite(t) && t <= Date.now()) {
			return 'The archive expired and was removed after 24 hours.'
		}
	}
	return ''
}

async function download(job: any) {
	await confirmPassword()
	window.location.href = downloadUrl(job.jobId)
}

function confirmDelete(job: any) {
	deleteTarget.value = job
}

async function deleteJob() {
	if (!deleteTarget.value) return
	const job = deleteTarget.value
	actionLoading.value = job.jobId
	deleteError.value = ''
	try {
		await confirmPassword()
		await axios.delete(jobUrl(job.jobId))
		if (selectedJob.value?.jobId === job.jobId) {
			// Without this the 2s timer keeps polling a job that now 404s. The
			// stopPolling() inside the tick is unreachable once fetchJob returns
			// null, so the interval ran forever.
			stopPolling()
			selectedJob.value = null
			events.value = []
		}
		await fetchJobs()
		deleteTarget.value = null
	} catch (e: any) {
		// Previously the finally closed the dialog regardless, so a failed
		// delete looked identical to a successful one.
		deleteError.value = e === 'cancelled'
			? 'Password confirmation was cancelled.'
			: (e?.response?.data?.ocs?.meta?.message || 'Could not delete this backup.')
	} finally {
		actionLoading.value = null
	}
}

async function resetAndReload() {
	stopPolling()
	stopRollbackPolling()
	jobs.value = []
	rollbackJobs.value = []
	selectedJob.value = null
	events.value = []
	deleteTarget.value = null
	actionLoading.value = null

	if (!organizationId.value) {
		initialLoading.value = false
		return
	}

	initialLoading.value = true
	try {
		await fetchJobs()
		await fetchRollbackJobs()
	} finally {
		initialLoading.value = false
	}
}

watch(organizationId, async (newId, oldId) => {
	if (newId === oldId) {
		return
	}
	await resetAndReload()
}, { immediate: true })

onBeforeUnmount(() => {
	stopPolling()
	stopRollbackPolling()
})
</script>

<style scoped lang="scss">
/* ─── Section Root ─── */
.backup-section {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 20px;
}

/* ─── Header / Intro ─── */
.backup-intro {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
}

.backup-actions {
	display: flex;
	align-items: flex-end;
	gap: 10px;
	flex-wrap: wrap;
}

.backup-type-picker {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.backup-type-picker label {
	font-size: var(--iz-fs-xs);
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--iz-text-secondary);
}

.backup-type-picker select {
	min-width: 150px;
	padding: 8px 30px 8px 10px;
	border: 1px solid var(--iz-border);
	border-radius: var(--iz-radius);
	background: var(--iz-surface);
	color: var(--iz-text);
	font-size: var(--iz-fs-md);
}

.intro-content {
	display: flex;
	align-items: center;
	gap: 14px;
	min-width: 0;
}

.intro-icon-wrap {
	width: 44px;
	height: 44px;
	border-radius: var(--iz-radius-lg);
	background: linear-gradient(135deg, var(--iz-accent), var(--iz-accent-bg));
	color: var(--iz-accent-text);
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

.intro-text {
	min-width: 0;
}

.intro-title {
	margin: 0 0 2px;
	font-size: var(--iz-fs-lg);
	font-weight: 700;
	line-height: 1.3;
}

.intro-desc {
	margin: 0;
	font-size: var(--iz-fs-md);
	color: var(--iz-text-secondary);
	line-height: 1.4;
}

/* ─── State Cards (Loading / Empty) ─── */
.state-card {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 12px;
	padding: 48px 24px;
	text-align: center;
	background: var(--iz-surface-subtle);
	border-radius: var(--iz-radius-lg);
	border: 1px dashed var(--iz-border);
}

.state-text {
	margin: 0;
	color: var(--iz-text-secondary);
	font-size: var(--iz-fs-lg);
}

.empty-icon-wrap {
	width: 72px;
	height: 72px;
	border-radius: 50%;
	background: var(--iz-surface-inset);
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--iz-text-secondary);
}

.empty-title {
	margin: 4px 0 0;
	font-size: var(--iz-fs-lg);
	font-weight: 600;
}

.empty-desc {
	margin: 0;
	max-width: 340px;
	font-size: var(--iz-fs-md);
	color: var(--iz-text-secondary);
	line-height: 1.5;
}

/* ─── Job Cards ─── */
.jobs-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.job-card {
	background: var(--iz-surface);
	border: 1px solid var(--iz-border);
	border-radius: var(--iz-radius-lg);
	overflow: hidden;
	cursor: pointer;
	transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.job-card:hover {
	border-color: var(--iz-accent-bg);
	box-shadow: var(--iz-shadow);
}

.job-card.expanded {
	border-color: var(--iz-accent-bg);
	box-shadow: var(--iz-shadow-lift);
}

/* ─── Job Summary Row ─── */
.job-summary {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 14px 16px;
	gap: 12px;
}

.job-left {
	display: flex;
	align-items: center;
	gap: 12px;
	min-width: 0;
	flex: 1;
}

.status-indicator {
	width: 36px;
	height: 36px;
	border-radius: var(--iz-radius-lg);
	border: 1px solid transparent;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	transition: background-color 0.2s ease, border-color 0.2s ease;
}

.status-indicator.queued {
	background: var(--iz-surface-inset);
	color: var(--iz-text-secondary);
	border-color: var(--iz-border);
}

.status-indicator.running {
	background: var(--iz-accent-bg);
	color: var(--iz-accent);
	border-color: var(--iz-accent);
}

.status-indicator.completed {
	background: var(--iz-success-bg);
	color: var(--iz-success);
	border-color: var(--iz-success);
}

.status-indicator.failed {
	background: var(--iz-danger-bg);
	color: var(--iz-danger);
	border-color: var(--iz-danger);
}

.job-info {
	min-width: 0;
	flex: 1;
}

.job-name-row {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 3px;
}

.job-name {
	font-weight: 600;
	font-size: var(--iz-fs-lg);
	white-space: nowrap;
}

.job-timestamps {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.timestamp {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: var(--iz-fs-sm);
	color: var(--iz-text-secondary);
}

.timestamp.expires {
	color: var(--iz-warning);
}

.job-right {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-shrink: 0;
}

.expand-icon {
	color: var(--iz-text-secondary);
	transition: transform 0.25s ease;
}

.expand-icon.rotated {
	transform: rotate(180deg);
}

/* ─── Error Banner ─── */
.error-banner {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 10px 16px;
	background: var(--iz-danger-bg);
	color: var(--iz-text);
	font-size: var(--iz-fs-md);
	line-height: 1.4;
	border-top: 1px solid var(--iz-danger);
	border-left: 3px solid var(--iz-danger);
}

/* ─── Progress Track (Running/Queued) ─── */
.progress-track {
	height: 3px;
	background: var(--iz-surface-inset);
	overflow: hidden;
}

.progress-fill {
	height: 100%;
	border-radius: 2px;
}

.progress-fill.running {
	width: 40%;
	background: var(--iz-accent);
	animation: progress-indeterminate 1.8s ease-in-out infinite;
}

.progress-fill.queued {
	width: 100%;
	background: var(--iz-surface-inset);
	opacity: 0.3;
	animation: progress-pulse 2s ease-in-out infinite;
}

@keyframes progress-indeterminate {
	0% { transform: translateX(-100%); }
	100% { transform: translateX(350%); }
}

@keyframes progress-pulse {
	0%, 100% { opacity: 0.15; }
	50% { opacity: 0.35; }
}

/* ─── Expanded Detail Panel ─── */
.job-detail {
	border-top: 1px solid var(--iz-border);
	padding: 16px;
	background: var(--iz-surface-subtle);
}

.detail-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
	gap: 12px;
	margin-bottom: 20px;
}

.detail-item {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.detail-label {
	font-size: var(--iz-fs-xs);
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--iz-text-secondary);
}

.detail-value {
	font-size: var(--iz-fs-md);
	font-weight: 500;
}

.detail-value.mono {
	font-family: 'SF Mono', 'Monaco', 'Inconsolata', 'Roboto Mono', monospace;
	font-size: var(--iz-fs-md);
}

.detail-value.capitalize {
	text-transform: capitalize;
}

/* ─── Events Timeline ─── */
.events-section {
	border-top: 1px solid var(--iz-border);
	padding-top: 16px;
}

.events-title {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0 0 14px;
	font-size: var(--iz-fs-lg);
	font-weight: 600;
}

.events-count {
	font-size: var(--iz-fs-xs);
	font-weight: 700;
	padding: 1px 7px;
	border-radius: var(--iz-radius-pill);
	background: var(--iz-accent);
	color: var(--iz-accent-text);
}

.events-empty {
	font-size: var(--iz-fs-md);
	color: var(--iz-text-secondary);
	padding: 12px 0;
}

.timeline {
	display: flex;
	flex-direction: column;
	max-height: 320px;
	overflow-y: auto;
	padding-right: 4px;
}

.timeline-item {
	display: flex;
	gap: 12px;
	min-height: 0;
}

.timeline-connector {
	display: flex;
	flex-direction: column;
	align-items: center;
	width: 16px;
	flex-shrink: 0;
	padding-top: 4px;
}

.timeline-dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	flex-shrink: 0;
	border: 2px solid;
}

.timeline-dot.info {
	border-color: var(--iz-accent);
	background: var(--iz-accent-bg);
}

.timeline-dot.warning {
	border-color: var(--iz-warning);
	background: var(--iz-warning-bg);
}

.timeline-dot.error {
	border-color: var(--iz-danger);
	background: var(--iz-danger-bg);
}

.timeline-line {
	width: 2px;
	flex: 1;
	background: var(--iz-border);
	margin: 4px 0;
	min-height: 12px;
}

.timeline-content {
	flex: 1;
	padding-bottom: 14px;
	min-width: 0;
}

.timeline-header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 3px;
}

.event-level-badge {
	font-size: var(--iz-fs-micro);
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	padding: 1px 6px;
	border-radius: var(--iz-radius-sm);
	border: 1px solid transparent;
}

.event-level-badge.info {
	background: var(--iz-accent-bg);
	color: var(--iz-text);
	border-color: var(--iz-accent);
}

.event-level-badge.warning {
	background: var(--iz-warning-bg);
	color: var(--iz-text);
	border-color: var(--iz-warning);
}

.event-level-badge.error {
	background: var(--iz-danger-bg);
	color: var(--iz-text);
	border-color: var(--iz-danger);
}

.event-time {
	font-size: var(--iz-fs-xs);
	color: var(--iz-text-secondary);
}

.event-message {
	margin: 0;
	font-size: var(--iz-fs-md);
	color: var(--iz-text-secondary);
	line-height: 1.45;
	word-break: break-word;
}

/* ─── Rollback Jobs ─── */
.rollback-panel {
	border: 1px solid var(--iz-border);
	border-radius: var(--iz-radius-lg);
	padding: 14px;
	background: var(--iz-surface);
}

.rollback-header h4 {
	margin: 0;
	font-size: var(--iz-fs-lg);
	font-weight: 700;
}

.rollback-empty {
	margin-top: 10px;
	font-size: var(--iz-fs-md);
	color: var(--iz-text-secondary);
}

.rollback-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 10px;
}

.rollback-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 10px;
	border: 1px solid var(--iz-border);
	border-radius: var(--iz-radius);
	padding: 10px;
	background: var(--iz-surface-subtle);
}

.rollback-main {
	min-width: 0;
}

.rollback-actions {
	display: flex;
	align-items: center;
}

.rollback-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.rollback-name {
	font-size: var(--iz-fs-md);
	font-weight: 700;
}

.rollback-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	margin-top: 4px;
	font-size: var(--iz-fs-sm);
	color: var(--iz-text-secondary);
}

.rollback-error {
	margin-top: 4px;
	font-size: var(--iz-fs-sm);
	color: var(--iz-danger);
}

.rollback-validation {
	margin-top: 8px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.rollback-validation-status {
	font-size: var(--iz-fs-sm);
	font-weight: 600;
	color: var(--iz-text-secondary);
}

.rollback-validation-status.blocked {
	color: var(--iz-danger);
}

.rollback-validation-status.ready {
	color: var(--iz-success);
}

.rollback-validation-section {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.rollback-validation-label {
	font-size: var(--iz-fs-sm);
	font-weight: 600;
	color: var(--iz-text-secondary);
}

.rollback-validation-list {
	margin: 0;
	padding-left: 18px;
	font-size: var(--iz-fs-sm);
	color: var(--iz-danger);
}

.rollback-validation-list.warnings {
	color: var(--iz-text-secondary);
}

.rollback-impact {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.rollback-impact-chip {
	border-radius: var(--iz-radius-pill);
	padding: 3px 8px;
	font-size: var(--iz-fs-sm);
	background: var(--iz-surface-inset);
	color: var(--iz-text);
}

@media (max-width: 600px) {
	.rollback-item {
		flex-direction: column;
		align-items: flex-start;
	}
}

/* ─── Delete Modal ─── */
.delete-warning {
	color: var(--iz-text-secondary);
	font-size: var(--iz-fs-lg);
	margin-top: 4px;
}

/* ─── Transitions ─── */
.expand-enter-active,
.expand-leave-active {
	transition: all 0.25s ease;
	overflow: hidden;
}

.expand-enter-from,
.expand-leave-to {
	opacity: 0;
	max-height: 0;
	padding: 0 16px;
}

.expand-enter-to,
.expand-leave-from {
	opacity: 1;
	max-height: 600px;
}

/* Job List Transition */
.job-list-enter-active,
.job-list-leave-active {
	transition: all 0.3s ease;
}

.job-list-enter-from {
	opacity: 0;
	transform: translateY(-8px);
}

.job-list-leave-to {
	opacity: 0;
	transform: translateX(20px);
}

.job-list-move {
	transition: transform 0.3s ease;
}

/* ─── Responsive ─── */
@media (max-width: 600px) {
	.backup-intro {
		flex-direction: column;
		align-items: flex-start;
	}

	.job-summary {
		flex-direction: column;
		align-items: flex-start;
	}

	.job-right {
		width: 100%;
		justify-content: flex-end;
	}

	.detail-grid {
		grid-template-columns: repeat(2, 1fr);
	}
}
</style>
