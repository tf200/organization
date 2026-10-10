<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import ConfirmDialog from '../../ConfirmDialog.vue'
import { ocs } from '../../../lib/api'
import type { Member, Organization, ProjectTeamAssignment, Team } from '../../../types'

const props = defineProps<{ org: Organization; members: Member[] }>()
const teams = ref<Team[]>([])
const projectTeams = ref<ProjectTeamAssignment[]>([])
const loading = ref(true)
const projectsLoading = ref(true)
const saving = ref(false)
const error = ref('')
const projectsError = ref('')
const assignmentBusy = ref<Record<number, boolean>>({})
const assignmentErrors = ref<Record<number, string>>({})
const showEditor = ref(false)
const deleteTarget = ref<Team | null>(null)
const draft = ref({ id: null as number | null, name: '', description: '' })
const selected = ref<string[]>([])

const isEditing = computed(() => draft.value.id !== null)
const canSave = computed(() => draft.value.name.trim() !== '')

/**
 * Normalize request failures for display.
 * @param e The caught value.
 */
function message(e: unknown): string {
	return e instanceof Error ? e.message : String(e)
}

/** Load this organization's teams. */
async function loadTeams() {
	loading.value = true
	error.value = ''
	try {
		const data = await ocs<{ teams?: Team[] }>(`organizations/${props.org.id}/teams`)
		teams.value = data?.teams ?? []
	} catch (e) {
		error.value = message(e)
	} finally {
		loading.value = false
	}
}

/** Load project assignment rows for this organization. */
async function loadProjectTeams() {
	projectsLoading.value = true
	projectsError.value = ''
	try {
		const data = await ocs<{ projectTeams?: ProjectTeamAssignment[] }>(`organizations/${props.org.id}/project-teams`)
		projectTeams.value = data?.projectTeams ?? []
	} catch (e) {
		projectsError.value = message(e)
	} finally {
		projectsLoading.value = false
	}
}

/** Load teams and project assignments together. */
async function load() {
	await Promise.all([loadTeams(), loadProjectTeams()])
}

onMounted(load)

/** Open an empty team editor. */
function openCreate() {
	draft.value = { id: null, name: '', description: '' }
	selected.value = []
	error.value = ''
	showEditor.value = true
}

/**
 * Open an existing team for editing.
 * @param team Team to edit.
 */
function openEdit(team: Team) {
	draft.value = {
		id: team.id,
		name: team.name,
		description: team.description ?? '',
	}
	selected.value = team.members.map((member) => member.uid)
	error.value = ''
	showEditor.value = true
}

/** Save team details, then synchronize member assignments. */
async function save() {
	if (!canSave.value) return
	saving.value = true
	error.value = ''
	try {
		const body = {
			name: draft.value.name.trim(),
			description: draft.value.description.trim() || null,
		}
		const data = await ocs<{ team: Team }>(
			draft.value.id === null ? `organizations/${props.org.id}/teams` : `organizations/${props.org.id}/teams/${draft.value.id}`,
			{ method: draft.value.id === null ? 'POST' : 'PUT', body },
		)
		const team = data.team
		const old = draft.value.id === null ? [] : teams.value.find((item) => item.id === draft.value.id)?.members.map((member) => member.uid) ?? []
		for (const uid of selected.value.filter((value) => !old.includes(value))) {
			await ocs(`organizations/${props.org.id}/teams/${team.id}/members`, { method: 'POST', body: { userId: uid } })
		}
		for (const uid of old.filter((value) => !selected.value.includes(value))) {
			await ocs(`organizations/${props.org.id}/teams/${team.id}/members/${encodeURIComponent(uid)}`, { method: 'DELETE' })
		}
		showEditor.value = false
		await load()
	} catch (e) {
		error.value = message(e)
	} finally {
		saving.value = false
	}
}

/** Delete the team selected in the confirmation dialog. */
async function removeTeam() {
	if (!deleteTarget.value) return
	saving.value = true
	error.value = ''
	try {
		await ocs(`organizations/${props.org.id}/teams/${deleteTarget.value.id}`, { method: 'DELETE' })
		deleteTarget.value = null
		await load()
	} catch (e) {
		error.value = message(e)
	} finally {
		saving.value = false
	}
}

const unassignedCount = computed(() => projectTeams.value.filter((project) => project.team === null).length)

/**
 * Projects assigned to a team; every member works on each of them.
 * @param team Team to count for.
 */
function projectCount(team: Team): number {
	return projectTeams.value.filter((project) => project.team?.id === team.id).length
}

/**
 * Save one project's responsible team without blocking the other rows.
 * @param project Project row being updated.
 * @param event Change event from the team select.
 */
async function saveAssignment(project: ProjectTeamAssignment, event: Event) {
	const select = event.target as HTMLSelectElement
	const value = select.value
	const teamId = value === '' ? null : Number(value)
	assignmentBusy.value[project.projectId] = true
	delete assignmentErrors.value[project.projectId]
	try {
		const data = await ocs<{ projectTeams?: ProjectTeamAssignment[] }>(
			`organizations/${props.org.id}/projects/${project.projectId}/team`,
			{ method: 'PUT', body: { teamId } },
		)
		if (data?.projectTeams) projectTeams.value = data.projectTeams
	} catch (e) {
		assignmentErrors.value[project.projectId] = message(e)
		select.value = String(project.team?.id ?? '')
	} finally {
		assignmentBusy.value[project.projectId] = false
	}
}
</script>

<template>
	<div class="teams">
		<div class="teams__toolbar">
			<span class="teams__count">{{ teams.length }} team{{ teams.length === 1 ? '' : 's' }}</span>
			<button class="iz-btn iz-btn--primary iz-btn--sm" type="button" @click="openCreate">
				+ New team
			</button>
		</div>

		<div v-if="loading" class="iz-state">
			Loading teams…
		</div>
		<div v-else-if="error && !showEditor" class="iz-error" role="alert">
			{{ error }}
			<button class="iz-btn iz-btn--accent iz-btn--sm" type="button" @click="load">
				Try again
			</button>
		</div>
		<div v-else-if="!teams.length" class="iz-empty">
			No teams yet. Create one to organize project capacity.
		</div>

		<ul v-else class="teams__list">
			<li v-for="team in teams" :key="team.id" class="iz-row iz-row--card teams__row">
				<div class="iz-identity__body">
					<span class="iz-identity__name">{{ team.name }}</span>
					<span class="iz-identity__meta">{{ team.description || 'No description' }}</span>
				</div>
				<div class="teams__stats">
					<span>{{ team.memberCount }} member{{ team.memberCount === 1 ? '' : 's' }}</span>
					<span>{{ projectCount(team) }} project{{ projectCount(team) === 1 ? '' : 's' }}</span>
				</div>
				<div class="teams__actions">
					<button class="iz-btn iz-btn--sm" type="button" @click="openEdit(team)">
						Edit
					</button>
					<button class="iz-btn iz-btn--danger iz-btn--sm" type="button" @click="deleteTarget = team">
						Delete
					</button>
				</div>
			</li>
		</ul>

		<section class="iz-panel iz-panel--list teams__assignments" aria-labelledby="project-teams-title">
			<div class="iz-panel__header">
				<div>
					<h4 id="project-teams-title" class="iz-panel__title">
						Responsible team by project
					</h4>
					<p class="iz-panel__subtitle">
						Assign one team to each project. Changes save automatically.
					</p>
				</div>
				<span class="iz-pill" :class="{ 'iz-pill--warning': unassignedCount > 0 }">
					{{ unassignedCount }} unassigned
				</span>
			</div>

			<div v-if="projectsLoading" class="iz-state">
				Loading project assignments…
			</div>
			<div v-else-if="projectsError" class="iz-error" role="alert">
				{{ projectsError }}
				<button class="iz-btn iz-btn--accent iz-btn--sm" type="button" @click="loadProjectTeams">
					Try again
				</button>
			</div>
			<div v-else-if="!projectTeams.length" class="iz-empty">
				No projects to assign yet.
			</div>
			<ul v-else class="teams__assignment-list">
				<li v-for="project in projectTeams" :key="project.projectId" class="teams__assignment-row">
					<div class="teams__assignment-project">
						<strong>
							{{ project.projectName }}
						</strong>
						<span v-if="assignmentErrors[project.projectId]" class="teams__assignment-error" role="alert">
							{{ assignmentErrors[project.projectId] }}
						</span>
					</div>
					<select class="iz-select teams__assignment-select"
						:disabled="assignmentBusy[project.projectId] || !teams.length"
						:aria-label="`Responsible team for ${project.projectName}`"
						:value="project.team?.id ?? ''"
						@change="saveAssignment(project, $event)">
						<option value="">
							Unassigned
						</option>
						<option v-for="team in teams" :key="team.id" :value="team.id">
							{{ team.name }}
						</option>
					</select>
					<span v-if="assignmentBusy[project.projectId]" class="teams__assignment-status" aria-live="polite">Saving…</span>
				</li>
			</ul>
		</section>

		<div v-if="showEditor" class="iz-modal-backdrop" @click.self="showEditor = false">
			<div class="iz-modal teams__modal" role="dialog" aria-modal="true">
				<div class="iz-modal__header">
					<h4 class="iz-panel__title">
						{{ isEditing ? 'Edit team' : 'New team' }}
					</h4>
					<button class="iz-close" type="button" @click="showEditor = false">
						&times;
					</button>
				</div>

				<form class="iz-modal__body teams__form" @submit.prevent="save">
					<label class="iz-label" for="team-name">Name</label>
					<input id="team-name"
						v-model="draft.name"
						class="iz-input"
						required>

					<label class="iz-label" for="team-description">Description</label>
					<textarea id="team-description"
						v-model="draft.description"
						class="iz-input"
						rows="2" />

					<fieldset class="teams__members">
						<legend class="iz-label">
							Members
						</legend>
						<label v-for="member in props.members" :key="member.uid" class="teams__member">
							<input v-model="selected" type="checkbox" :value="member.uid">
							{{ member.displayName || member.uid }}
						</label>
						<span v-if="!props.members.length" class="iz-state">Add organization members before assigning teams.</span>
					</fieldset>

					<div v-if="error" class="iz-error" role="alert">
						{{ error }}
					</div>
					<div class="iz-modal__footer">
						<button class="iz-btn iz-btn--sm"
							type="button"
							:disabled="saving"
							@click="showEditor = false">
							Cancel
						</button>
						<button class="iz-btn iz-btn--primary iz-btn--sm" type="submit" :disabled="saving || !canSave">
							{{ saving ? 'Saving…' : 'Save team' }}
						</button>
					</div>
				</form>
			</div>
		</div>

		<ConfirmDialog v-if="deleteTarget"
			:title="`Delete ${deleteTarget.name}?`"
			message="Team membership assignments will also be removed."
			confirm-label="Delete team"
			busy-label="Deleting…"
			danger
			:busy="saving"
			:error="error"
			@confirm="removeTeam"
			@cancel="deleteTarget = null; error = ''" />
	</div>
</template>

<style scoped>
.teams {
	display: flex;
	flex-direction: column;
	gap: var(--iz-gap);
}

.teams__toolbar,
.teams__actions,
.teams__stats {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}

.teams__count {
	margin-right: auto;
	color: var(--iz-text-secondary);
}

.teams__list {
	display: flex;
	flex-direction: column;
	gap: 10px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.teams__assignments {
	margin-top: var(--iz-gap);
}

.teams__assignment-list {
	display: flex;
	flex-direction: column;
	gap: 1px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.teams__assignment-row {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(180px, 280px) auto;
	align-items: center;
	gap: 12px;
	padding: 12px var(--iz-pad-panel);
	border-top: 1px solid var(--iz-border);
}

.teams__assignment-project,
.teams__assignment-error {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.teams__assignment-error {
	color: var(--color-danger);
	font-size: var(--iz-fs-sm);
}

.teams__assignment-select {
	width: auto;
}

.teams__assignment-status {
	color: var(--iz-text-secondary);
	font-size: var(--iz-fs-sm);
}

.teams__row {
	flex-wrap: wrap;
}

.teams__stats {
	color: var(--iz-text-secondary);
	font-size: var(--iz-fs-sm);
}

.teams__modal {
	width: 100%;
	max-width: 640px;
}

.teams__form {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.teams__members {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 10px;
	border: 1px solid var(--iz-border);
}

.teams__member {
	font-size: var(--iz-fs-sm);
}

@media (max-width: 600px) {
	.teams__actions {
		width: 100%;
	}

	.teams__assignment-row {
		grid-template-columns: 1fr auto;
	}

	.teams__assignment-select {
		min-width: 0;
		max-width: 190px;
	}

	.teams__assignment-status {
		grid-column: 2;
		grid-row: 1;
	}
}
</style>
