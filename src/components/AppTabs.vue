<script setup lang="ts">
import type { TabKey } from '../types'

defineProps<{
	modelValue: TabKey
	counts?: Partial<Record<TabKey, number>>
}>()

const emit = defineEmits<{ 'update:modelValue': [TabKey] }>()

const STORAGE_KEY = 'organization:activeTab'

/* Sentence case: .iz-tabs--display uppercases via text-transform. */
const TABS: { key: TabKey; label: string }[] = [
	{ key: 'organizations', label: 'Organizations' },
	{ key: 'plans', label: 'Plans' },
	{ key: 'trial', label: 'Trial defaults' },
]

function select(key: TabKey) {
	emit('update:modelValue', key)
	window.localStorage.setItem(STORAGE_KEY, key)
}
</script>

<template>
	<nav class="iz-tabs iz-tabs--display" role="tablist">
		<button
			v-for="tab in TABS"
			:key="tab.key"
			class="iz-tab"
			:class="{ 'iz-tab--active': modelValue === tab.key }"
			type="button"
			role="tab"
			:aria-selected="modelValue === tab.key"
			@click="select(tab.key)">
			{{ tab.label }}
			<span v-if="counts?.[tab.key] !== undefined" class="iz-tab__count">{{ counts[tab.key] }}</span>
		</button>
	</nav>
</template>

<!-- No <style>. The tab bar is entirely theme chrome. -->
