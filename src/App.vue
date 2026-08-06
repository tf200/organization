<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AppTabs from './components/AppTabs.vue'
import OrgPanel from './components/organizations/OrgPanel.vue'
import type { TabKey } from './types'

const counts = ref<Partial<Record<TabKey, number>>>({})

/* This app is superadmin-only — PageController rejects everyone else — so
   there is no permission branching, no mode chip and no conditional nav. */

const VALID: TabKey[] = ['organizations', 'plans', 'trial']
const activeTab = ref<TabKey>('organizations')

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

		<OrgPanel
			v-if="activeTab === 'organizations'"
			@count="counts.organizations = $event" />

		<section v-else-if="activeTab === 'plans'" class="iz-panel">
			<div class="iz-panel__header">
				<h3 class="iz-panel__title">Plans</h3>
			</div>
		</section>

		<section v-else class="iz-panel">
			<div class="iz-panel__header">
				<h3 class="iz-panel__title">Trial defaults</h3>
			</div>
		</section>
	</div>
</template>
