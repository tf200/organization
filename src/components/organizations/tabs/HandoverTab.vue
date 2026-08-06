<template>
	<div class="account-handover">
		<div v-if="!selectedJob" class="handover-main">
			<!-- New Handover Form -->
			<div class="handover-form section">
				<h3>Start New Handover</h3>
				<p class="section-description">
					Transfer ownership of projects, deck boards, and other content from one member to another.
				</p>

				<div class="form-grid">
					<div class="form-group">
						<label for="source-member">Source member</label>
						<IzSelect v-model="form.sourceUserId"
							input-id="source-member"
							aria-label="Source member"
							:options="memberOptions"
							placeholder="Select source member"
							:disabled="loading" />
					</div>

					<div class="form-group">
						<label for="target-member">Target member</label>
						<IzSelect v-model="form.targetUserId"
							input-id="target-member"
							aria-label="Target member"
							:options="memberOptions"
							placeholder="Select target member"
							:disabled="loading" />
					</div>
				</div>

				<div class="options-grid">
					<IzSwitch v-model="form.removeSourceFromGroups"
						type="switch"
						:disabled="loading">
						Remove source member from project groups
					</IzSwitch>

					<IzSwitch v-model="form.remapDeckContent"
						type="switch"
						:disabled="loading">
						Remap Deck content (boards, cards)
					</IzSwitch>
				</div>

				<div class="form-actions">
					<IzButton type="tertiary"
						:disabled="!isFormValid || loading"
						@click="runDryRun">
						<template #icon>
							<IzSpinner v-if="loading" :size="20" />
							<Play v-else :size="20" />
						</template>
						Preview (Dry Run)
					</IzButton>
					<IzButton type="primary"
						:disabled="!isFormValid || loading"
						@click="startTransfer">
						<template #icon>
							<IzSpinner v-if="loading" :size="20" />
							<Play v-else :size="20" />
						</template>
						Start Transfer
					</IzButton>
				</div>
			</div>

			<!-- Jobs History -->
			<div class="handover-history section">
				<div class="section-header">
					<h3>Recent Jobs</h3>
					<IzButton type="tertiary"
						:disabled="loadingJobs"
						@click="fetchJobs">
						<template #icon>
							<Refresh :class="{ 'spinning': loadingJobs }" :size="18" />
						</template>
						Refresh
					</IzButton>
				</div>

				<div v-if="loadingJobs && jobs.length === 0" class="loading-state">
					<IzSpinner :size="48" />
					<p>Loading jobs...</p>
				</div>

				<div v-else-if="jobs.length === 0" class="empty-state">
					<History :size="48" />
					<p>No handover jobs found.</p>
				</div>

				<div v-else class="jobs-list">
					<table class="jobs-table">
						<thead>
							<tr>
								<th>Status</th>
								<th>Source / Target</th>
								<th>Type</th>
								<th>Created</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="job in jobs"
								:key="job.jobId"
								class="job-row"
								@click="viewJobDetails(job)">
								<td>
									<div :class="['status-badge', job.status]">
										{{ job.status }}
									</div>
								</td>
								<td>
									<div class="transfer-info">
										<span class="uid">{{ job.sourceUserId }}</span>
										<ChevronRight :size="14" />
										<span class="uid">{{ job.targetUserId }}</span>
									</div>
								</td>
								<td>
									<span v-if="job.dryRun" class="type-tag dry-run">Dry Run</span>
									<span v-else class="type-tag real">Real</span>
								</td>
								<td>
									<IzDateTime :timestamp="new Date(job.createdAt).getTime()" />
								</td>
								<td class="actions-cell">
									<IzButton v-if="job.status === 'failed'"
										type="tertiary"
										title="Retry"
										@click.stop="retryJob(job)">
										<template #icon>
											<Refresh :size="18" />
										</template>
									</IzButton>
									<IzButton type="tertiary"
										title="View Details"
										@click.stop="viewJobDetails(job)">
										<template #icon>
											<Information :size="18" />
										</template>
									</IzButton>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>

		<!-- Job Details View -->
		<div v-else class="job-details">
			<div class="details-header">
				<IzButton type="tertiary" @click="selectedJob = null">
					<template #icon>
						<ChevronLeft :size="20" />
					</template>
					Back to list
				</IzButton>
				<h2>Job #{{ selectedJob.jobId }} Details</h2>
				<div :class="['status-badge', selectedJob.status]">
					{{ selectedJob.status }}
				</div>
			</div>

			<div class="details-grid">
				<!-- Summary Card -->
				<div class="details-card summary">
					<h3>Summary</h3>
					<div class="summary-info">
						<div class="info-item">
							<span class="label">Source:</span>
							<span class="value">{{ selectedJob.sourceUserId }}</span>
						</div>
						<div class="info-item">
							<span class="label">Target:</span>
							<span class="value">{{ selectedJob.targetUserId }}</span>
						</div>
						<div class="info-item">
							<span class="label">Type:</span>
							<span class="value">{{ selectedJob.dryRun ? 'Dry Run' : 'Real Transfer' }}</span>
						</div>
						<div class="info-item">
							<span class="label">Requested by:</span>
							<span class="value">{{ selectedJob.requestedByUserId }}</span>
						</div>
						<div class="info-item">
							<span class="label">Attempt:</span>
							<span class="value">{{ selectedJob.attempt }}</span>
						</div>
						<div class="info-item">
							<span class="label">Created:</span>
							<span class="value"><IzDateTime :timestamp="new Date(selectedJob.createdAt).getTime()" /></span>
						</div>
						<div v-if="selectedJob.startedAt" class="info-item">
							<span class="label">Started:</span>
							<span class="value"><IzDateTime :timestamp="new Date(selectedJob.startedAt).getTime()" /></span>
						</div>
						<div v-if="selectedJob.finishedAt" class="info-item">
							<span class="label">Finished:</span>
							<span class="value"><IzDateTime :timestamp="new Date(selectedJob.finishedAt).getTime()" /></span>
						</div>
					</div>

					<div v-if="selectedJob.errorMessage" class="error-banner">
						<AlertCircle :size="20" />
						<div class="error-content">
							<strong>Error:</strong>
							<p>{{ selectedJob.errorMessage }}</p>
						</div>
						<IzButton v-if="selectedJob.status === 'failed'"
							type="primary"
							@click="retryJob(selectedJob)">
							Retry Failed Steps
						</IzButton>
					</div>
				</div>

				<!-- Options Card -->
				<div class="details-card options">
					<h3>Configuration</h3>
					<ul class="options-list">
						<li :class="{ enabled: selectedJob.dryRun }">
							<Check v-if="selectedJob.dryRun" :size="16" />
							<Close v-else :size="16" />
							Dry run mode
						</li>
						<li :class="{ enabled: selectedJob.removeSourceFromGroups }">
							<Check v-if="selectedJob.removeSourceFromGroups" :size="16" />
							<Close v-else :size="16" />
							Remove source from project groups
						</li>
						<li :class="{ enabled: selectedJob.remapDeckContent }">
							<Check v-if="selectedJob.remapDeckContent" :size="16" />
							<Close v-else :size="16" />
							Remap Deck content
						</li>
					</ul>
				</div>

				<!-- Steps Card -->
				<div class="details-card steps">
					<h3>Execution Steps</h3>
					<div class="steps-list">
						<div v-for="step in selectedJob.steps" :key="step.id" class="step-item">
							<div class="step-icon">
								<IzSpinner v-if="step.status === 'running'" :size="20" />
								<CheckCircle v-else-if="step.status === 'completed'" :size="20" class="success" />
								<Information v-else-if="step.status === 'skipped'" :size="20" class="skipped" />
								<CloseCircle v-else-if="step.status === 'failed'" :size="20" class="error" />
								<Timer v-else :size="20" class="pending" />
							</div>
							<div class="step-info">
								<div class="step-name">
									{{ formatStepName(step.stepKey) }}
								</div>
								<div class="step-meta">
									<span class="status">{{ step.status }}</span>
									<span v-if="step.attempt > 1" class="attempt">• Attempt {{ step.attempt }}</span>
								</div>
								<div v-if="step.result?.warning" class="step-warning">
									{{ step.result.warning }}
								</div>
								<div v-if="step.errorMessage" class="step-error">
									{{ step.errorMessage }}
								</div>
								<details v-if="step.result" class="step-details">
									<summary>Details</summary>
									<pre>{{ formatJson(step.result) }}</pre>
								</details>
							</div>
						</div>
					</div>
				</div>

				<!-- Events Card -->
				<div class="details-card events">
					<div class="card-header">
						<h3>Activity Log</h3>
						<IzButton type="tertiary" @click="fetchEvents(selectedJob.jobId)">
							<template #icon>
								<Refresh :size="16" />
							</template>
						</IzButton>
					</div>
					<div ref="eventsStream" class="events-stream">
						<div v-if="loadingEvents" class="events-loading">
							<IzSpinner :size="24" />
						</div>
						<div v-else-if="events.length === 0" class="events-empty">
							No events logged yet.
						</div>
						<div v-for="event in events"
							v-else
							:key="event.id"
							:class="['event-item', event.level]">
							<div class="event-time">
								{{ formatTime(event.createdAt) }}
							</div>
							<div class="event-message">
								{{ event.message }}
							</div>
							<div v-if="event.stepKey" class="event-meta">
								Step: {{ formatStepName(event.stepKey) }}
							</div>
							<div v-if="event.payload" class="event-payload">
								<div class="event-summary">
									{{ formatPayloadSummary(event.payload) }}
								</div>
								<details class="event-details">
									<summary>Payload</summary>
									<pre>{{ formatJson(event.payload) }}</pre>
								</details>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import IzSelect from '../../ui/IzSelect.vue'
import IzButton from '../../ui/IzButton.vue'
import IzSwitch from '../../ui/IzSwitch.vue'
import IzDateTime from '../../ui/IzDateTime.vue'
import IzSpinner from '../../ui/IzSpinner.vue'
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

import Play from 'vue-material-design-icons/Play.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import History from 'vue-material-design-icons/History.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import Information from 'vue-material-design-icons/Information.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import Timer from 'vue-material-design-icons/Timer.vue'

const props = defineProps<{
	organization: any
	members: any[]
}>()

const loading = ref(false)
const loadingJobs = ref(false)
const loadingEvents = ref(false)
const jobs = ref<any[]>([])
const events = ref<any[]>([])
const selectedJob = ref<any>(null)

const form = ref({
	sourceUserId: '',
	targetUserId: '',
	removeSourceFromGroups: false,
	remapDeckContent: true,
})

const memberOptions = computed(() => {
	return props.members.map(m => ({
		id: m.uid,
		label: `${m.displayName} (${m.uid})`,
	}))
})

const isFormValid = computed(() => {
	return !!(form.value.sourceUserId
		&& form.value.targetUserId
		&& form.value.sourceUserId !== form.value.targetUserId)
})

const fetchJobs = async () => {
	if (!props.organization) return
	loadingJobs.value = true
	try {
		const response = await axios.get(
			generateOcsUrl(`apps/organization/organizations/${props.organization.id}/handover/jobs`),
		)
		jobs.value = response.data.ocs.data.jobs || []
	} catch (error) {
		console.error('Failed to fetch jobs', error)
	} finally {
		loadingJobs.value = false
	}
}

const fetchJob = async (jobId: number) => {
	const response = await axios.get(
		generateOcsUrl(`apps/organization/organizations/${props.organization.id}/handover/jobs/${jobId}`),
	)
	return response.data.ocs.data
}

const fetchEvents = async (jobId: number) => {
	loadingEvents.value = true
	try {
		const response = await axios.get(
			generateOcsUrl(`apps/organization/organizations/${props.organization.id}/handover/jobs/${jobId}/events`),
		)
		events.value = response.data.ocs.data.events || []
	} catch (error) {
		console.error('Failed to fetch events', error)
	} finally {
		loadingEvents.value = false
	}
}

const startHandover = async (dryRun: boolean) => {
	if (!isFormValid.value || !props.organization) return

	loading.value = true
	const idempotencyKey = globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random()}`

	try {
		const response = await axios.post(
			generateOcsUrl(`apps/organization/organizations/${props.organization.id}/handover`),
			{
				sourceUserId: form.value.sourceUserId,
				targetUserId: form.value.targetUserId,
				dryRun,
				removeSourceFromGroups: form.value.removeSourceFromGroups,
				remapDeckContent: form.value.remapDeckContent,
			},
			{
				headers: {
					'Idempotency-Key': idempotencyKey,
				},
			},
		)

		await fetchJobs()

		const job = response.data.ocs.data
		if (job?.jobId) {
			await viewJobDetails(job)
		}
	} catch (error: any) {
		console.error('Failed to start handover', error)
	} finally {
		loading.value = false
	}
}

const runDryRun = async () => {
	await startHandover(true)
}

const startTransfer = async () => {
	if (!props.organization || !isFormValid.value) return
	const ok = window.confirm('Start the real transfer? This will modify ownership and memberships.')
	if (!ok) return
	await startHandover(false)
}

const retryJob = async (job: any) => {
	loading.value = true
	try {
		const response = await axios.post(
			generateOcsUrl(`apps/organization/organizations/${props.organization.id}/handover/jobs/${job.jobId}/retry`),
		)
		await fetchJobs()
		if (selectedJob.value?.jobId === job.jobId) {
			selectedJob.value = response.data.ocs.data
		}
	} catch (error) {
		console.error('Failed to retry job', error)
	} finally {
		loading.value = false
	}
}

const viewJobDetails = async (job: any) => {
	const jobId: number = job?.jobId ?? job
	const base = job && typeof job === 'object' ? job : {}
	selectedJob.value = {
		...base,
		jobId,
		steps: base?.steps ?? [],
	}
	events.value = []
	await fetchEvents(jobId)

	// If job is still running, poll for updates
	try {
		selectedJob.value = await fetchJob(jobId)
	} catch (error) {
		console.error('Failed to fetch job details', error)
	}

	if (selectedJob.value?.status === 'queued' || selectedJob.value?.status === 'running') {
		startPolling(jobId)
	}
}

let pollingInterval: ReturnType<typeof setInterval> | null = null
const startPolling = (jobId: number) => {
	if (pollingInterval) clearInterval(pollingInterval)
	pollingInterval = setInterval(async () => {
		if (!selectedJob.value || selectedJob.value.jobId !== jobId) {
			stopPolling()
			return
		}

		try {
			const response = await axios.get(
				generateOcsUrl(`apps/organization/organizations/${props.organization.id}/handover/jobs/${jobId}`),
			)
			selectedJob.value = response.data.ocs.data
			await fetchEvents(jobId)

			if (selectedJob.value.status !== 'queued' && selectedJob.value.status !== 'running') {
				stopPolling()
				fetchJobs() // Update the main list
			}
		} catch (error) {
			console.error('Polling failed', error)
			stopPolling()
		}
	}, 3000)
}

const stopPolling = () => {
	if (pollingInterval) {
		clearInterval(pollingInterval)
		pollingInterval = null
	}
}

const formatStepName = (key: string) => {
	const names: Record<string, string> = {
		projectcreator: 'Project Creator Ownership Transfer',
		deck: 'Deck Boards & Cards Remapping',
		finalize: 'Finalization & Cleanup',
	}
	return names[key] || key
}

const formatTime = (dateStr: string) => {
	const date = new Date(dateStr)
	return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

const formatJson = (value: any) => {
	try {
		return JSON.stringify(value, null, 2)
	} catch {
		return String(value)
	}
}

const formatPayloadSummary = (payload: any) => {
	if (!payload || typeof payload !== 'object') return ''
	const parts: string[] = []
	if (payload.status) parts.push(`status=${payload.status}`)
	if (payload.service) parts.push(`service=${payload.service}`)
	if (payload.organizationId) parts.push(`org=${payload.organizationId}`)
	if (payload.sourceUserId) parts.push(`from=${payload.sourceUserId}`)
	if (payload.targetUserId) parts.push(`to=${payload.targetUserId}`)
	return parts.filter(Boolean).join(' ')
}

onMounted(() => {
	fetchJobs()
})

onBeforeUnmount(() => {
	stopPolling()
})

watch(() => props.organization?.id, () => {
	fetchJobs()
	selectedJob.value = null
})
</script>

<style scoped>
.account-handover {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.section {
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
}

.section h3 {
	margin: 0 0 8px 0;
	font-size: 1.1rem;
	font-weight: 600;
}

.section-description {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	margin-bottom: 20px;
}

.form-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 20px;
	margin-bottom: 20px;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.form-group label {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.options-grid {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-bottom: 24px;
	padding: 16px;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	flex-wrap: wrap;
}

/* History Section */
.section-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.loading-state, .empty-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px;
	color: var(--color-text-maxcontrast);
	text-align: center;
	gap: 12px;
}

.jobs-list {
	overflow-x: auto;
}

.jobs-table {
	width: 100%;
	border-collapse: collapse;
}

.jobs-table th {
	text-align: left;
	padding: 12px;
	border-bottom: 2px solid var(--color-border);
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.jobs-table td {
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
	font-size: 0.95rem;
}

.job-row {
	cursor: pointer;
	transition: background-color 0.2s;
}

.job-row:hover {
	background-color: var(--color-background-hover);
}

.status-badge {
	display: inline-block;
	padding: 4px 10px;
	border-radius: 999px;
	font-size: 0.75rem;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.03em;
}

.status-badge.queued { background-color: var(--color-background-dark); color: var(--color-text-maxcontrast); }
.status-badge.running { background-color: var(--color-primary-light); color: var(--color-primary); }
.status-badge.completed { background-color: var(--color-success-light); color: var(--color-success); }
.status-badge.failed { background-color: var(--color-error-light); color: var(--color-error); }
.status-badge.skipped { background-color: var(--color-background-dark); color: var(--color-text-maxcontrast); }

.transfer-info {
	display: flex;
	align-items: center;
	gap: 8px;
}

.uid {
	font-family: monospace;
	font-size: 0.85em;
	background-color: var(--color-background-dark);
	padding: 2px 6px;
	border-radius: 4px;
}

.type-tag {
	font-size: 0.75rem;
	font-weight: 600;
	padding: 2px 6px;
	border-radius: 4px;
}

.type-tag.dry-run { background-color: var(--color-warning-light); color: var(--color-warning); }
.type-tag.real { background-color: var(--color-info-light); color: var(--color-info); }

.actions-cell {
	display: flex;
	gap: 4px;
}

.spinning {
	animation: spin 2s linear infinite;
}

@keyframes spin {
	from { transform: rotate(0deg); }
	to { transform: rotate(360deg); }
}

/* Job Details */
.job-details {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.details-header {
	display: flex;
	align-items: center;
	gap: 16px;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
}

.details-header h2 {
	margin: 0;
	flex: 1;
}

.details-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 20px;
}

.details-card {
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
}

.details-card h3 {
	margin: 0 0 16px 0;
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.summary-info {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.info-item {
	display: flex;
	justify-content: space-between;
	font-size: 0.95rem;
}

.info-item .label {
	color: var(--color-text-maxcontrast);
}

.info-item .value {
	font-weight: 600;
}

.error-banner {
	margin-top: 20px;
	padding: 16px;
	background-color: var(--color-error-light);
	border-radius: var(--border-radius);
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.error-content {
	font-size: 0.9rem;
}

.error-content p {
	margin: 4px 0 0 0;
}

.options-list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.options-list li {
	display: flex;
	align-items: center;
	gap: 10px;
	font-size: 0.95rem;
	color: var(--color-text-maxcontrast);
}

.options-list li.enabled {
	color: var(--color-main-text);
	font-weight: 500;
}

.steps-list {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.step-item {
	display: flex;
	gap: 12px;
}

.step-icon {
	flex-shrink: 0;
	margin-top: 2px;
}

.step-icon .success { color: var(--color-success); }
.step-icon .error { color: var(--color-error); }
.step-icon .skipped { color: var(--color-text-maxcontrast); }
.step-icon .pending { color: var(--color-text-maxcontrast); }

.step-info {
	flex: 1;
}

.step-name {
	font-weight: 600;
	font-size: 0.95rem;
}

.step-meta {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	text-transform: capitalize;
}

.step-error {
	margin-top: 4px;
	font-size: 0.85rem;
	color: var(--color-error);
	background-color: var(--color-error-light);
	padding: 4px 8px;
	border-radius: 4px;
}

.step-warning {
	margin-top: 4px;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	background-color: var(--color-background-dark);
	padding: 4px 8px;
	border-radius: 4px;
}

.step-details {
	margin-top: 8px;
}

.step-details summary {
	cursor: pointer;
	color: var(--color-primary);
}

.step-details pre {
	margin: 8px 0 0 0;
	padding: 8px;
	background-color: var(--iz-surface-inset);
	border-radius: 4px;
	overflow-x: auto;
}

.events {
	grid-column: span 2;
}

.card-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.events-stream {
	max-height: 300px;
	overflow-y: auto;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	font-family: monospace;
	font-size: 0.85rem;
}

.event-item {
	display: flex;
	gap: 12px;
	padding: 4px 8px;
	border-radius: 4px;
}

.event-item.info { color: var(--iz-text-secondary); }
.event-item.warning { background-color: var(--iz-warning-bg); color: var(--iz-warning-text); }
.event-item.error { background-color: var(--iz-danger-bg); color: var(--iz-danger-text); }

.event-time {
	flex-shrink: 0;
	opacity: 0.7;
}

.event-payload {
	margin-top: 4px;
	padding: 8px;
	background-color: var(--iz-surface-inset);
	border-radius: 4px;
	overflow-x: auto;
}

.event-meta {
	opacity: 0.8;
}

.event-summary {
	opacity: 0.9;
}

.event-details summary {
	cursor: pointer;
	color: var(--color-primary);
}

.event-payload pre {
	margin: 0;
}

@media (max-width: 800px) {
	.form-grid {
		grid-template-columns: 1fr;
	}

	.details-grid {
		grid-template-columns: 1fr;
	}

	.events {
		grid-column: span 1;
	}
}
</style>
