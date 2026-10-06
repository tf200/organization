<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { generateUrl } from '@nextcloud/router'
import { ocs } from '../../../lib/api'
import { formatDate, formatDateTime } from '../../../lib/format'
import type { ExternalActivity, ExternalCollaborator, ExternalSeats, Organization } from '../../../types'

/**
 * Who outside the organization works on its projects. Read only: externals
 * are invited, moved and revoked on a project's Members tab.
 */
const props = defineProps<{ org: Organization }>()
const emit = defineEmits<{ counted: [number] }>()

const externals = ref<ExternalCollaborator[]>([])
const seats = ref<ExternalSeats | null>(null)
const activity = ref<ExternalActivity[]>([])
const loading = ref(true)
const error = ref('')

/** Load the organization's externals, its seat count and what happened lately. */
async function load() {
	loading.value = true
	error.value = ''
	try {
		const [data, log] = await Promise.all([
			ocs<{ externals?: ExternalCollaborator[], seats?: ExternalSeats }>(`organizations/${props.org.id}/externals`),
			ocs<{ entries?: ExternalActivity[] }>(`organizations/${props.org.id}/externals/activity`),
		])
		externals.value = data?.externals ?? []
		seats.value = data?.seats ?? null
		activity.value = log?.entries ?? []
		emit('counted', externals.value.length)
	} catch (e) {
		error.value = e instanceof Error ? e.message : String(e)
	} finally {
		loading.value = false
	}
}

onMounted(load)

const seatsLine = computed(() => {
	if (!seats.value) return ''
	const of = seats.value.max === null ? `${seats.value.used} seats used` : `${seats.value.used} of ${seats.value.max} seats used`
	const n = seats.value.externals
	return `${of} · ${n} by external collaborator${n === 1 ? '' : 's'}`
})

const STATUS: Record<string, { label: string, tone: string }> = {
	invited: { label: 'Invited', tone: 'iz-badge--warning' },
	active: { label: 'Active', tone: 'iz-badge--success' },
	suspended: { label: 'Suspended', tone: 'iz-badge--danger' },
	disabled: { label: 'Disabled', tone: 'iz-badge--muted' },
}

/**
 * The account's status as a badge.
 * @param external The external collaborator.
 */
function status(external: ExternalCollaborator) {
	return STATUS[external.accountStatus ?? ''] ?? { label: 'Unknown', tone: 'iz-badge--muted' }
}

/**
 * Where a project opens in the project app.
 * @param projectId The project.
 */
function projectUrl(projectId: number): string {
	return generateUrl(`/apps/projectcreatoraio/new/projects/${projectId}/members`)
}

/**
 * One project of an external: pending, or when access ends.
 * @param project The grant on that project.
 */
function projectState(project: ExternalCollaborator['projects'][number]): string {
	if (project.status === 'pending') return 'invitation pending'
	return project.expiresAt ? `until ${formatDate(project.expiresAt)}` : 'active'
}

/**
 * One audit entry as a sentence.
 * @param entry The entry.
 */
function describe(entry: ExternalActivity): string {
	const who = entry.displayName
	const by = entry.actorName ? ` by ${entry.actorName}` : ''
	const on = entry.projectName ? ` ${entry.projectName}` : ' the project'
	const details = entry.details ?? {}
	switch (entry.action) {
	case 'invited': return `${who} was invited to${on}${by}`
	case 'invite_resent': return `The invitation of ${who} to${on} was sent again${by}`
	case 'accepted': return `${who} accepted the invitation to${on} and the terms`
	case 'end_date_changed': return `Access of ${who} to${on} now ends ${details.to ? formatDate(String(details.to)) : 'later'}${by}`
	case 'roles_changed': return `Roles of ${who} on${on} changed${by}`
	case 'revoked': return details.accepted ? `Access of ${who} to${on} was revoked${by}` : `The invitation of ${who} to${on} was cancelled${by}`
	case 'expired': return details.reason === 'not_accepted' ? `The invitation of ${who} to${on} lapsed unaccepted` : `Access of ${who} to${on} ended on its end date`
	case 'link_requested': return `${who} asked for a new invitation link to${on}`
	case 'account_disabled': return `The account of ${who} was disabled after 30 days without access`
	case 'account_deleted': return `The account of ${who} was deleted`
	default: return `${who}: ${entry.action}`
	}
}
</script>

<template>
	<div class="externals">
		<div class="externals__toolbar">
			<span v-if="seatsLine" class="externals__count">{{ seatsLine }}</span>
			<button class="iz-btn iz-btn--sm"
				type="button"
				:disabled="loading"
				@click="load">
				Refresh
			</button>
		</div>

		<p class="iz-inset">
			External collaborators are clients, grid operators and subcontractors with access to one or more projects only. Each takes one member seat. Invite them, change their end date or revoke them on the project's Members tab.
		</p>

		<div v-if="error" class="iz-error" role="alert">
			{{ error }}
			<button class="iz-btn iz-btn--accent iz-btn--sm" type="button" @click="load">
				Try again
			</button>
		</div>
		<p v-else-if="loading" class="iz-state">
			Loading external collaborators…
		</p>
		<div v-else-if="!externals.length" class="iz-empty">
			No external collaborators.
		</div>
		<ul v-else class="externals__list">
			<li v-for="external in externals"
				:key="external.userId"
				class="iz-row iz-row--card externals__row">
				<span class="iz-identity__avatar iz-identity__avatar--sm iz-identity__avatar--soft" aria-hidden="true">
					{{ (external.displayName || external.email || '?').charAt(0).toUpperCase() }}
				</span>
				<div class="iz-identity__body">
					<span class="iz-identity__name">
						{{ external.displayName || external.email }}<template v-if="external.company"> · {{ external.company }}</template>
					</span>
					<span class="iz-identity__meta">
						{{ external.email }} · {{ external.lastSeenAt ? 'last seen ' + formatDateTime(external.lastSeenAt) : 'never signed in' }}
					</span>
					<span class="externals__projects">
						<a v-for="project in external.projects"
							:key="project.projectId"
							class="iz-pill externals__project"
							:href="projectUrl(project.projectId)">
							{{ project.projectName }} · {{ projectState(project) }}
						</a>
					</span>
				</div>
				<span class="iz-badge" :class="status(external).tone">{{ status(external).label }}</span>
			</li>
		</ul>

		<section v-if="!loading && !error && activity.length" class="externals__activity">
			<span class="iz-section-title">Activity</span>
			<ul class="externals__log">
				<li v-for="entry in activity" :key="entry.id" class="externals__entry">
					<time class="externals__when" :datetime="entry.createdAt">{{ formatDateTime(entry.createdAt) }}</time>
					<span>{{ describe(entry) }}</span>
				</li>
			</ul>
		</section>
	</div>
</template>

<style scoped>
/* Layout only. */
.externals {
	display: flex;
	flex-direction: column;
	gap: var(--iz-gap);
}

.externals__toolbar {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.externals__count {
	font-size: var(--iz-fs-sm);
	color: var(--iz-text-secondary);
	margin-right: auto;
}

.externals__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.externals__projects {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 6px;
}

.externals__project {
	text-decoration: none;
}

.externals__activity {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 8px;
}

.externals__log {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
	font-size: var(--iz-fs-sm);
}

.externals__entry {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
}

.externals__when {
	color: var(--iz-text-secondary);
	min-width: 130px;
}
</style>
