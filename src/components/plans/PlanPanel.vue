<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import PlanRow from './PlanRow.vue'
import { ocs } from '../../lib/api'
import { useAsync } from '../../composables/useAsync'
import type { Plan } from '../../types'

const plans = ref<Plan[]>([])
const expandedId = ref<number | null>(null)
const search = ref('')

const emit = defineEmits<{ count: [number] }>()

const list = useAsync(async () => {
	const data = await ocs<{ plans: Plan[] }>('plans')
	plans.value = data?.plans ?? []
	emit('count', plans.value.length)
	return plans.value
})

onMounted(() => list.run())

/* Same two fields the old client-side search matched. */
const filtered = computed(() => {
	const q = search.value.trim().toLowerCase()
	if (!q) return plans.value
	return plans.value.filter((p) =>
		p.name.toLowerCase().includes(q) || String(p.id).includes(q))
})

async function toggle(id: number) {
	if (expandedId.value === id) {
		expandedId.value = null
		return
	}
	expandedId.value = id
	// subscriptionCount only comes back from the single-plan endpoint.
	try {
		const detail = await ocs<Plan>(`plans/${id}`)
		const i = plans.value.findIndex((p) => p.id === id)
		if (i !== -1 && detail) plans.value[i] = { ...plans.value[i], ...detail }
	} catch {
		// Non-fatal: the row still renders from the list payload.
	}
}
</script>

<template>
	<section class="iz-panel iz-panel--list">
		<div class="iz-panel__header">
			<h3 class="iz-panel__title">
				Subscription plans
				<span v-if="plans.length" class="iz-badge iz-badge--muted">{{ plans.length }}</span>
			</h3>
			<button class="iz-btn iz-btn--primary iz-btn--sm" type="button">
				+ New plan
			</button>
		</div>

		<div class="plan-panel__toolbar">
			<input v-model="search"
				class="iz-input plan-panel__search"
				type="search"
				placeholder="Search by name or ID…"
				aria-label="Search plans">
		</div>

		<div class="plan-panel__body">
			<div v-if="list.pending.value && !plans.length" class="plan-panel__state">
				<span class="iz-spinner iz-spinner--lg" aria-label="Loading plans" />
			</div>

			<div v-else-if="list.error.value" class="iz-error" role="alert">
				{{ list.error.value }}
				<button class="iz-btn iz-btn--accent iz-btn--sm" type="button" @click="list.run()">
					Try again
				</button>
			</div>

			<div v-else-if="!plans.length" class="iz-empty">
				No plans yet. Create one to get started.
			</div>

			<div v-else-if="!filtered.length" class="iz-empty">
				<p class="plan-panel__empty-text">
					No plans match “{{ search.trim() }}”.
				</p>
				<button class="iz-btn iz-btn--plain iz-btn--sm" type="button" @click="search = ''">
					Clear search
				</button>
			</div>

			<div v-else class="plan-panel__rows">
				<PlanRow v-for="plan in filtered"
					:key="plan.id"
					:plan="plan"
					:expanded="expandedId === plan.id"
					@toggle="toggle(plan.id)"
					@deleted="list.run()" />
			</div>
		</div>
	</section>
</template>

<style scoped>
/* Layout only. */
.plan-panel__toolbar {
	display: flex;
	gap: 10px;
	padding: var(--iz-pad-card);
	border-bottom: 1px solid var(--iz-border);
}

/* Qualified on the toolbar: an unqualified class ties with .iz-app .iz-input
   at (0,2,0), and this app's CSS loads before the theme, so the theme wins
   the tie. See OrgPanel for the full note. */
.plan-panel__toolbar .plan-panel__search {
	flex: 1 1 220px;
	min-width: 0;
	width: auto;
}

.plan-panel__body {
	padding: var(--iz-pad-card);
}

.plan-panel__rows {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.plan-panel__state {
	display: flex;
	justify-content: center;
	padding: 32px 0;
}

.plan-panel__empty-text {
	margin: 0 0 10px;
}
</style>
