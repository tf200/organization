<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AppTabs from './components/AppTabs.vue'
import OrgPanel from './components/organizations/OrgPanel.vue'
import PlanPanel from './components/plans/PlanPanel.vue'
import TrialDefaultsPanel from './components/trial/TrialDefaultsPanel.vue'
import type { TabKey } from './types'

/* This app is superadmin-only — PageController rejects everyone else — so
   there is no permission branching, no mode chip and no conditional nav. */

const VALID: TabKey[] = ['organizations', 'plans', 'trial']
const activeTab = ref<TabKey>('organizations')
const counts = ref<Partial<Record<TabKey, number>>>({})

onMounted(() => {
	const stored = window.localStorage.getItem('organization:activeTab')
	if (stored && (VALID as string[]).includes(stored)) {
		activeTab.value = stored as TabKey
	}
})
</script>

<template>
	<div class="org-dashboard iz-app">
		<AppTabs v-model="activeTab" :counts="counts" />

		<OrgPanel v-if="activeTab === 'organizations'"
			@count="counts.organizations = $event" />

		<PlanPanel v-else-if="activeTab === 'plans'"
			@count="counts.plans = $event" />

		<TrialDefaultsPanel v-else />
	</div>
</template>
