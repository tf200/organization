<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import OrgRow from './OrgRow.vue'
import OrgDetail from './OrgDetail.vue'
import CreateOrgModal from './CreateOrgModal.vue'
import EditOrgModal from './EditOrgModal.vue'
import ConvertTrialModal from './ConvertTrialModal.vue'
import { ocs } from '../../lib/api'
import { useAsync } from '../../composables/useAsync'
import type { Organization, Plan } from '../../types'

const organizations = ref<Organization[]>([])
const expandedId = ref<number | null>(null)

/* Plans are needed by the create and convert modals, not by the list itself,
   so a failure here must not take the organization list down with it. */
const plans = ref<Plan[]>([])

const showCreate = ref(false)
const editTarget = ref<Organization | null>(null)
const convertTarget = ref<Organization | null>(null)

const search = ref('')
const typeFilter = ref<'all' | 'standard' | 'trial'>('all')
const statusFilter = ref('all')

const emit = defineEmits<{ count: [number] }>()

const list = useAsync(async () => {
	const data = await ocs<{ organizations: Organization[] }>('organizations')
	organizations.value = data?.organizations ?? []
	emit('count', organizations.value.length)
	return organizations.value
})

async function loadPlans() {
	try {
		const data = await ocs<{ plans: Plan[] }>('plans')
		plans.value = data?.plans ?? []
	} catch {
		// Non-fatal: the create modal falls back to its "Custom plan" option.
		plans.value = []
	}
}

onMounted(() => {
	list.run()
	loadPlans()
})

function onCreated() {
	showCreate.value = false
	list.run()
}

function onEdited(updated: Partial<Organization> & { name?: string }) {
	const id = editTarget.value?.id
	editTarget.value = null
	if (id === undefined) return
	const i = organizations.value.findIndex((o) => o.id === id)
	if (i !== -1) {
		// The API may return `name` where the list holds `displayname`.
		const displayname = updated?.name || updated?.displayname || organizations.value[i].displayname
		organizations.value[i] = { ...organizations.value[i], ...updated, displayname }
	}
	list.run()
}

function onConverted() {
	convertTarget.value = null
	list.run()
}

/* Search matches displayname and the stringified id, as the old list did. */
const filtered = computed(() => {
	const q = search.value.trim().toLowerCase()
	return organizations.value.filter((org) => {
		if (q && !org.displayname.toLowerCase().includes(q) && !String(org.id).includes(q)) return false
		if (typeFilter.value !== 'all' && org.type !== typeFilter.value) return false
		if (statusFilter.value !== 'all' && org.subscription?.status !== statusFilter.value) return false
		return true
	})
})

const statuses = computed(() => {
	const seen = new Set<string>()
	for (const o of organizations.value) {
		if (o.subscription?.status) seen.add(o.subscription.status)
	}
	return [...seen].sort()
})

function clearFilters() {
	search.value = ''
	typeFilter.value = 'all'
	statusFilter.value = 'all'
}

function toggle(id: number) {
	expandedId.value = expandedId.value === id ? null : id
}

/**
 * Merge detail fetched by the row back into the list entry.
 * @param id
 * @param patchData
 */
function patch(id: number, patchData: Partial<Organization>) {
	const i = organizations.value.findIndex((o) => o.id === id)
	if (i !== -1) organizations.value[i] = { ...organizations.value[i], ...patchData }
}

defineExpose({ reload: () => list.run() })
</script>

<template>
	<section class="iz-panel iz-panel--list">
		<div class="iz-panel__header">
			<h3 class="iz-panel__title">
				Organizations
				<span v-if="organizations.length" class="iz-badge iz-badge--muted">{{ organizations.length }}</span>
			</h3>
			<button class="iz-btn iz-btn--primary iz-btn--sm" type="button" @click="showCreate = true">
				+ New organization
			</button>
		</div>

		<div class="org-panel__toolbar">
			<input v-model="search"
				class="iz-input org-panel__search"
				type="search"
				placeholder="Search by name or ID…"
				aria-label="Search organizations">
			<select v-model="typeFilter" class="iz-select org-panel__filter" aria-label="Filter by type">
				<option value="all">
					All types
				</option>
				<option value="standard">
					Standard
				</option>
				<option value="trial">
					Trial
				</option>
			</select>
			<select v-model="statusFilter" class="iz-select org-panel__filter" aria-label="Filter by status">
				<option value="all">
					All statuses
				</option>
				<option v-for="s in statuses" :key="s" :value="s">
					{{ s }}
				</option>
			</select>
		</div>

		<div class="org-panel__body">
			<div v-if="list.pending.value && !organizations.length" class="org-panel__state">
				<span class="iz-spinner iz-spinner--lg" aria-label="Loading organizations" />
			</div>

			<div v-else-if="list.error.value" class="iz-error" role="alert">
				{{ list.error.value }}
				<button class="iz-btn iz-btn--accent iz-btn--sm" type="button" @click="list.run()">
					Try again
				</button>
			</div>

			<div v-else-if="!organizations.length" class="iz-empty">
				No organizations yet. Create one to get started.
			</div>

			<!-- Distinct from the above: the old UI showed "Get started by creating
			     a new organization" even when a filter was the cause. -->
			<div v-else-if="!filtered.length" class="iz-empty">
				<p class="org-panel__empty-text">
					No organizations match the current filters.
				</p>
				<button class="iz-btn iz-btn--plain iz-btn--sm" type="button" @click="clearFilters">
					Clear filters
				</button>
			</div>

			<div v-else class="org-panel__rows">
				<OrgRow v-for="org in filtered"
					:key="org.id"
					:org="org"
					:expanded="expandedId === org.id"
					@toggle="toggle(org.id)">
					<OrgDetail :org="org"
						@patch="patch(org.id, $event)"
						@edit="editTarget = $event"
						@convert="convertTarget = $event"
						@changed="list.run()" />
				</OrgRow>
			</div>
		</div>

		<CreateOrgModal :show="showCreate"
			:plans="plans"
			@close="showCreate = false"
			@success="onCreated" />

		<EditOrgModal :show="!!editTarget"
			:organization="editTarget"
			@close="editTarget = null"
			@saved="onEdited" />

		<ConvertTrialModal :show="!!convertTarget"
			:organization="convertTarget"
			:plans="plans"
			@close="convertTarget = null"
			@success="onConverted" />
	</section>
</template>

<style scoped>
/* Layout only. */
.org-panel__toolbar {
	display: flex;
	gap: 10px;
	align-items: center;
	flex-wrap: wrap;
	padding: var(--iz-pad-card);
	border-bottom: 1px solid var(--iz-border);
}

/* .iz-input and .iz-select are width:100% in the theme — right for a stacked
   field, wrong in a toolbar row (USING-THE-THEME.md §5).
   These are qualified on the toolbar to reach (0,3,0). An unqualified
   .org-panel__search would tie with .iz-app .iz-input at (0,2,0), and unlike
   the webpack siblings this app's CSS is a linked file that Nextcloud loads
   BEFORE the theme — so on a tie the theme wins here, not the app. */
.org-panel__toolbar .org-panel__search {
	flex: 1 1 220px;
	min-width: 0;
	width: auto;
}

.org-panel__toolbar .org-panel__filter {
	flex: 0 0 auto;
	width: auto;
	min-width: 150px;
}

.org-panel__body {
	padding: var(--iz-pad-card);
}

.org-panel__rows {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.org-panel__state {
	display: flex;
	justify-content: center;
	padding: 32px 0;
}

.org-panel__empty-text {
	margin: 0 0 10px;
}
</style>
