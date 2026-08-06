<script setup lang="ts">
/**
 * Account handover: move one member's projects, Deck content and group
 * memberships to another.
 *
 * Rebuilt on the same expandable rows as the backups tab and the organization
 * list, replacing a drill-in "Back to list" view that hid the list behind a
 * third level of navigation — inside a tab, inside an expanded organization.
 *
 * Three fixes that are not cosmetic:
 *   - the real transfer was gated by window.confirm(), which the app forbids;
 *     it is a ConfirmDialog now, so a failure keeps the dialog open with a
 *     reason instead of vanishing;
 *   - every request swallowed its failure into console.error, so a rejected
 *     handover looked exactly like one that never started;
 *   - the job list was pinned to the server's first 20 with no way forward.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import ConfirmDialog from '../../ConfirmDialog.vue'
import Pagination from '../../ui/Pagination.vue'
import IzChevron from '../../ui/IzChevron.vue'
import IzSelect from '../../ui/IzSelect.vue'
import IzSwitch from '../../ui/IzSwitch.vue'
import JobSteps from '../../jobs/JobSteps.vue'
import JobEvents from '../../jobs/JobEvents.vue'
import { ocs } from '../../../lib/api'
import { formatDateTime } from '../../../lib/format'
import { isActive, statusLabel, statusTone } from '../../../lib/jobs'
import type { HandoverJob, JobEvent, Member, Organization } from '../../../types'

const props = defineProps<{
	org: Organization
	members: Member[]
}>()

const JOBS_PAGE = 20
const EVENTS_PAGE = 200

const jobs = ref<HandoverJob[]>([])
const jobsOffset = ref(0)
const loading = ref(true)
const listError = ref('')

const busy = ref(false)
const formError = ref('')
const confirmTransfer = ref(false)

const openJobId = ref<number | null>(null)
const job = ref<HandoverJob | null>(null)
const events = ref<JobEvent[]>([])
const detailPending = ref(false)
const detailError = ref('')

const retryingId = ref<number | null>(null)

const form = ref({
	sourceUserId: '',
	targetUserId: '',
	removeSourceFromGroups: false,
	remapDeckContent: true,
})

let pollTimer: ReturnType<typeof setInterval> | null = null

const orgId = computed(() => Number(props.org?.id || 0))
const base = () => `organizations/${orgId.value}/handover`

const memberOptions = computed(() =>
	props.members.map((m) => ({ id: m.uid, label: `${m.displayName || m.uid} (${m.uid})` })))

const sameMember = computed(() =>
	!!form.value.sourceUserId && form.value.sourceUserId === form.value.targetUserId)

const canSubmit = computed(() =>
	!!form.value.sourceUserId && !!form.value.targetUserId && !sameMember.value)

/**
 * Turn any thrown value into a sentence worth showing.
 * @param e
 */
function describe(e: unknown): string {
	return e instanceof Error ? e.message : String(e)
}

/* ── Fetching ───────────────────────────────────────────────────────────── */

/**
 * Load the current page of backup jobs.
 */
async function fetchJobs() {
	const data = await ocs<{ jobs: HandoverJob[] }>(`${base()}/jobs`, {
		params: { limit: JOBS_PAGE, offset: jobsOffset.value },
	})
	jobs.value = data?.jobs ?? []
	if (!jobs.value.length && jobsOffset.value > 0) {
		jobsOffset.value = Math.max(0, jobsOffset.value - JOBS_PAGE)
		return
	}
	if (jobs.value.some((j) => isActive(j.status))) startPolling()
	else stopPolling()
}

/**
 * Load the current page of handover jobs, surfacing any failure.
 */
async function load() {
	loading.value = true
	listError.value = ''
	try {
		await fetchJobs()
	} catch (e) {
		listError.value = describe(e)
	} finally {
		loading.value = false
	}
}

/**
 * The single-job endpoint answers the job bare at ocs.data, not wrapped in
 * `{job: …}` the way the backup endpoints do. Steps only come back from here.
 * @param jobId
 */
async function loadDetail(jobId: number) {
	detailPending.value = true
	detailError.value = ''
	try {
		const [detail, log] = await Promise.all([
			ocs<HandoverJob>(`${base()}/jobs/${jobId}`),
			ocs<{ events: JobEvent[] }>(`${base()}/jobs/${jobId}/events`, {
				params: { limit: EVENTS_PAGE, offset: 0 },
			}),
		])
		job.value = detail ?? null
		events.value = log?.events ?? []
	} catch (e) {
		detailError.value = describe(e)
	} finally {
		detailPending.value = false
	}
}

/* ── Polling ────────────────────────────────────────────────────────────── */

/**
 * False while the tab is in the background, so a tick can be skipped.
 */
function pollable(): boolean {
	return typeof document === 'undefined' || document.visibilityState === 'visible'
}

/**
 * Refresh every 3s while a job is queued or running. Idempotent.
 *
 * A real transfer is queued and picked up by a background job on a 60s timer,
 * so it can sit in `queued` for a while before anything moves — the poll has
 * to outlast that, not give up after a few ticks.
 */
function startPolling() {
	if (pollTimer) return
	pollTimer = setInterval(async () => {
		if (!pollable()) return
		try {
			await fetchJobs()
			if (openJobId.value !== null) await loadDetail(openJobId.value)
		} catch {
			stopPolling()
		}
	}, 3000)
}

/**
 * Cancel the poll.
 */
function stopPolling() {
	if (pollTimer) {
		clearInterval(pollTimer)
		pollTimer = null
	}
}

/**
 * Catch up immediately when the tab comes back to the foreground.
 */
function onVisibility() {
	if (pollable() && pollTimer) fetchJobs().catch(() => stopPolling())
}

/* ── Actions ────────────────────────────────────────────────────────────── */

/**
 * Create a handover job, as a dry run or for real, and open it.
 * @param dryRun
 */
async function start(dryRun: boolean) {
	if (!canSubmit.value) return
	busy.value = true
	formError.value = ''
	try {
		const created = await ocs<HandoverJob>(base(), {
			method: 'POST',
			body: {
				sourceUserId: form.value.sourceUserId,
				targetUserId: form.value.targetUserId,
				dryRun,
				removeSourceFromGroups: form.value.removeSourceFromGroups,
				remapDeckContent: form.value.remapDeckContent,
			},
			// Replaying the same request must not start a second transfer.
			headers: {
				'Idempotency-Key': globalThis.crypto?.randomUUID?.()
					?? `${orgId.value}-${form.value.sourceUserId}-${form.value.targetUserId}-${performance.now()}`,
			},
		})
		jobsOffset.value = 0
		await fetchJobs()
		if (created?.jobId) {
			openJobId.value = created.jobId
			await loadDetail(created.jobId)
			if (isActive(created.status)) startPolling()
		}
		confirmTransfer.value = false
	} catch (e) {
		formError.value = describe(e)
	} finally {
		busy.value = false
	}
}

/**
 * Retries only the failed steps — the server's default for this endpoint.
 * @param row
 */
async function retry(row: HandoverJob) {
	retryingId.value = row.jobId
	formError.value = ''
	try {
		const updated = await ocs<HandoverJob>(`${base()}/jobs/${row.jobId}/retry`, { method: 'POST' })
		await fetchJobs()
		if (openJobId.value === row.jobId) {
			job.value = updated ?? job.value
			await loadDetail(row.jobId)
		}
		if (updated && isActive(updated.status)) startPolling()
	} catch (e) {
		formError.value = describe(e)
	} finally {
		retryingId.value = null
	}
}

/**
 * Expand or collapse a handover job, loading its detail on open.
 * @param row
 */
async function toggle(row: HandoverJob) {
	if (openJobId.value === row.jobId) {
		openJobId.value = null
		job.value = null
		events.value = []
		return
	}
	openJobId.value = row.jobId
	job.value = null
	events.value = []
	await loadDetail(row.jobId)
}

/* ── Dry-run preview ────────────────────────────────────────────────────── */

/**
 * Read one key off an unknown value without asserting its shape.
 * @param value
 * @param key
 */
function pick(value: unknown, key: string): unknown {
	return value && typeof value === 'object' && !Array.isArray(value)
		? (value as Record<string, unknown>)[key]
		: undefined
}

/**
 * The whole point of a dry run, and it was only ever reachable by opening a
 * raw JSON dump under the finalize step.
 */
const preview = computed<Record<string, unknown> | null>(() => {
	const found = pick(pick(job.value?.result, 'finalize'), 'dryRunPreview')
	return found && typeof found === 'object' && !Array.isArray(found)
		? found as Record<string, unknown>
		: null
})

const previewFacts = computed(() =>
	Object.entries(preview.value ?? {})
		.filter(([key, value]) => key !== 'warnings' && key !== 'mode'
			&& (typeof value === 'number' || typeof value === 'string')))

const previewWarnings = computed(() => {
	const found = preview.value?.warnings
	return Array.isArray(found) ? found.filter((v): v is string => typeof v === 'string') : []
})

/**
 * Turn an API key such as `deckBoards` into "Deck boards".
 * @param key
 */
function humanKey(key: string): string {
	const spaced = key.replace(/([A-Z])/g, ' $1').replace(/_/g, ' ').trim()
	return spaced.charAt(0).toUpperCase() + spaced.slice(1)
}

/**
 * Pretty-print the job result for the raw disclosure.
 * @param value
 */
function rawResult(value: unknown): string {
	try {
		return JSON.stringify(value, null, 2)
	} catch {
		return String(value)
	}
}

/* ── Lifecycle ──────────────────────────────────────────────────────────── */

onMounted(() => {
	load()
	document.addEventListener('visibilitychange', onVisibility)
})

onBeforeUnmount(() => {
	stopPolling()
	document.removeEventListener('visibilitychange', onVisibility)
})

watch(orgId, (next, prev) => {
	if (next === prev) return
	stopPolling()
	openJobId.value = null
	job.value = null
	events.value = []
	jobsOffset.value = 0
	form.value = {
		sourceUserId: '',
		targetUserId: '',
		removeSourceFromGroups: false,
		remapDeckContent: true,
	}
	load()
})

watch(jobsOffset, async () => {
	openJobId.value = null
	job.value = null
	events.value = []
	try {
		await fetchJobs()
	} catch (e) {
		listError.value = describe(e)
	}
})
</script>

<template>
	<div class="handover">
		<!-- ── Start a handover ─────────────────────────────────────────── -->
		<section class="iz-panel iz-panel--flush">
			<header class="iz-panel__header">
				<h4 class="iz-panel__title">
					Start a handover
				</h4>
			</header>

			<div class="iz-card handover__form">
				<p class="handover__note">
					Transfers ownership of projects, Deck boards and other content from one member to
					another. Run a preview first — it reports exactly what would move without moving
					anything.
				</p>

				<div class="handover__grid">
					<div class="handover__field">
						<label class="iz-label" for="handover-source">Source member</label>
						<IzSelect id="handover-source"
							v-model="form.sourceUserId"
							input-id="handover-source"
							aria-label="Source member"
							:options="memberOptions"
							placeholder="Select source member"
							:disabled="busy" />
					</div>

					<div class="handover__field">
						<label class="iz-label" for="handover-target">Target member</label>
						<IzSelect id="handover-target"
							v-model="form.targetUserId"
							input-id="handover-target"
							aria-label="Target member"
							:options="memberOptions"
							placeholder="Select target member"
							:disabled="busy" />
					</div>
				</div>

				<div class="iz-inset handover__options">
					<IzSwitch v-model="form.removeSourceFromGroups" :disabled="busy">
						Remove the source member from project groups
					</IzSwitch>
					<IzSwitch v-model="form.remapDeckContent" :disabled="busy">
						Remap Deck content (boards and cards)
					</IzSwitch>
				</div>

				<p v-if="sameMember" class="iz-state">
					The source and target must be different members.
				</p>

				<div v-if="formError" class="iz-error" role="alert">
					{{ formError }}
				</div>

				<div class="handover__actions">
					<button class="iz-btn iz-btn--sm"
						type="button"
						:disabled="!canSubmit || busy"
						@click="start(true)">
						<span v-if="busy" class="iz-spinner" />
						Preview (dry run)
					</button>
					<button class="iz-btn iz-btn--primary iz-btn--sm"
						type="button"
						:disabled="!canSubmit || busy"
						@click="confirmTransfer = true">
						Start transfer
					</button>
				</div>
			</div>
		</section>

		<!-- ── Jobs ─────────────────────────────────────────────────────── -->
		<section class="iz-panel iz-panel--flush">
			<header class="iz-panel__header">
				<h4 class="iz-panel__title">
					Handover jobs
					<span v-if="jobs.length" class="iz-badge iz-badge--muted">{{ jobs.length }}</span>
				</h4>
				<button class="iz-btn iz-btn--sm"
					type="button"
					:disabled="loading"
					@click="load">
					<span v-if="loading" class="iz-spinner" />
					Refresh
				</button>
			</header>

			<div v-if="listError" class="iz-error handover__alert" role="alert">
				{{ listError }}
				<button class="iz-btn iz-btn--accent iz-btn--sm" type="button" @click="load">
					Try again
				</button>
			</div>

			<p v-if="loading && !jobs.length" class="iz-state">
				Loading handover jobs…
			</p>

			<div v-else-if="!jobs.length" class="iz-empty">
				No handover jobs yet.
			</div>

			<template v-else>
				<div class="handover__rows">
					<article v-for="row in jobs"
						:key="row.jobId"
						class="iz-row iz-row--card iz-row--expandable"
						:class="{ 'iz-row--expanded': openJobId === row.jobId }">
						<div class="iz-row__header"
							role="button"
							tabindex="0"
							:aria-expanded="openJobId === row.jobId"
							@click="toggle(row)"
							@keydown.enter.prevent="toggle(row)"
							@keydown.space.prevent="toggle(row)">
							<div class="handover__ident">
								<span class="handover__transfer">
									<span class="handover__uid">{{ row.sourceUserId }}</span>
									<span aria-label="to">→</span>
									<span class="handover__uid">{{ row.targetUserId }}</span>
								</span>
								<span class="handover__meta">
									Job #{{ row.jobId }} · {{ formatDateTime(row.createdAt) }} · by {{ row.requestedByUserId }}
								</span>
							</div>

							<div class="iz-row__actions">
								<span class="iz-badge" :class="row.dryRun ? 'iz-badge--warning' : 'iz-badge--accent'">
									{{ row.dryRun ? 'Dry run' : 'Real transfer' }}
								</span>
								<span class="iz-pill" :class="statusTone(row.status)">
									<span class="iz-dot" aria-hidden="true" />{{ statusLabel(row.status) }}
								</span>
								<button v-if="row.status === 'failed'"
									class="iz-btn iz-btn--sm"
									type="button"
									:disabled="retryingId === row.jobId"
									title="Re-run only the steps that failed"
									@click.stop="retry(row)">
									<span v-if="retryingId === row.jobId" class="iz-spinner" />
									Retry failed steps
								</button>
								<IzChevron :open="openJobId === row.jobId" />
							</div>
						</div>

						<div v-if="openJobId === row.jobId" class="iz-row__detail handover__detail">
							<p v-if="detailPending && !job" class="iz-state">
								Loading job detail…
							</p>
							<div v-if="detailError" class="iz-error" role="alert">
								{{ detailError }}
							</div>

							<template v-if="job">
								<div v-if="job.errorMessage" class="iz-error" role="alert">
									{{ job.errorMessage }}
								</div>

								<div v-if="preview" class="iz-inset handover__preview">
									<span class="iz-section-title">Preview — nothing was changed</span>
									<div v-if="previewFacts.length" class="handover__chips">
										<span v-for="[key, value] in previewFacts" :key="key" class="iz-badge iz-badge--muted">
											{{ humanKey(key) }}: {{ value }}
										</span>
									</div>
									<ul v-if="previewWarnings.length" class="handover__bullets">
										<li v-for="(text, i) in previewWarnings" :key="i">
											{{ text }}
										</li>
									</ul>
								</div>

								<dl class="iz-kv">
									<div class="iz-kv__item">
										<dt class="iz-kv__label">
											Source
										</dt>
										<dd class="iz-kv__value iz-kv__value--mono">
											{{ job.sourceUserId }}
										</dd>
									</div>
									<div class="iz-kv__item">
										<dt class="iz-kv__label">
											Target
										</dt>
										<dd class="iz-kv__value iz-kv__value--mono">
											{{ job.targetUserId }}
										</dd>
									</div>
									<div class="iz-kv__item">
										<dt class="iz-kv__label">
											Requested by
										</dt>
										<dd class="iz-kv__value">
											{{ job.requestedByUserId }}
										</dd>
									</div>
									<div class="iz-kv__item">
										<dt class="iz-kv__label">
											Attempt
										</dt>
										<dd class="iz-kv__value">
											{{ job.attempt }}
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
											Started
										</dt>
										<dd class="iz-kv__value">
											{{ formatDateTime(job.startedAt) }}
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
								</dl>

								<section class="handover__section">
									<span class="iz-section-title">Configuration</span>
									<div class="handover__chips">
										<span class="iz-badge" :class="job.dryRun ? 'iz-badge--warning' : 'iz-badge--accent'">
											{{ job.dryRun ? 'Dry run' : 'Real transfer' }}
										</span>
										<span class="iz-badge"
											:class="job.removeSourceFromGroups ? 'iz-badge--success' : 'iz-badge--muted'">
											{{ job.removeSourceFromGroups ? 'Removes source from groups' : 'Keeps source in groups' }}
										</span>
										<span class="iz-badge" :class="job.remapDeckContent ? 'iz-badge--success' : 'iz-badge--muted'">
											{{ job.remapDeckContent ? 'Remaps Deck content' : 'Leaves Deck content' }}
										</span>
									</div>
								</section>

								<section v-if="job.steps.length" class="handover__section">
									<span class="iz-section-title">Steps</span>
									<JobSteps :steps="job.steps" />
								</section>

								<section class="handover__section">
									<span class="iz-section-title">
										Activity log
										<span v-if="events.length" class="iz-badge iz-badge--muted">{{ events.length }}</span>
									</span>
									<JobEvents :events="events" :loading="detailPending" show-step />
								</section>

								<details v-if="job.result" class="handover__raw">
									<summary>Raw result</summary>
									<pre class="iz-code">{{ rawResult(job.result) }}</pre>
								</details>
							</template>
						</div>
					</article>
				</div>

				<Pagination v-model:offset="jobsOffset"
					:limit="JOBS_PAGE"
					:count="jobs.length"
					label="handover jobs" />
			</template>
		</section>

		<!-- Replaces window.confirm(), which the app forbids: a native confirm
		     cannot show why the request failed, and dismissed itself either way. -->
		<ConfirmDialog v-if="confirmTransfer"
			title="Start the real transfer?"
			:message="`Ownership of ${form.sourceUserId}'s projects and Deck content moves to ${form.targetUserId}. This runs for real and is not reversible from here. Run a dry run first if you have not.`"
			confirm-label="Start transfer"
			busy-label="Starting…"
			danger
			:busy="busy"
			:error="formError"
			@confirm="start(false)"
			@cancel="confirmTransfer = false; formError = ''" />
	</div>
</template>

<style scoped>
/* Layout only. Surfaces, borders, radii, type sizes, pills, badges, insets,
   errors and the row shell all come from .iz-* primitives. */
.handover {
	display: flex;
	flex-direction: column;
	gap: var(--iz-gap);
}

.handover__form {
	display: flex;
	flex-direction: column;
	gap: var(--iz-gap);
}

.handover__note {
	margin: 0;
	font-size: var(--iz-fs-md);
	color: var(--iz-text-secondary);
	line-height: 1.45;
}

.handover__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: var(--iz-gap);
}

.handover__field {
	min-width: 0;
}

.handover__options {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.handover__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	flex-wrap: wrap;
}

.handover__alert {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: var(--iz-gap);
}

.handover__rows {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.handover__ident {
	display: flex;
	flex-direction: column;
	gap: 1px;
	min-width: 0;
	flex: 1;
}

.handover__transfer {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: var(--iz-fs-md);
	font-weight: 600;
	min-width: 0;
}

.handover__uid {
	font-family: var(--iz-font-mono);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.handover__meta {
	font-size: var(--iz-fs-xs);
	color: var(--iz-text-muted);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.handover__detail {
	display: flex;
	flex-direction: column;
	gap: var(--iz-gap);
}

.handover__section {
	display: flex;
	flex-direction: column;
}

.handover__chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.handover__preview {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.handover__bullets {
	margin: 0;
	padding-left: 18px;
	font-size: var(--iz-fs-md);
	color: var(--iz-text-secondary);
}

.handover__raw summary {
	font-size: var(--iz-fs-sm);
	color: var(--iz-text-secondary);
	cursor: pointer;
}

</style>
