<script setup lang="ts">
/** Replacement for NcDateTime. */
import { computed } from 'vue'
import { formatDateTime } from '../../lib/format'

const props = defineProps<{ timestamp?: number | string | null }>()

const label = computed(() => {
	if (props.timestamp === null || props.timestamp === undefined) return '—'
	if (typeof props.timestamp === 'number') {
		return formatDateTime(new Date(props.timestamp).toISOString())
	}
	return formatDateTime(props.timestamp)
})
</script>

<template>
	<span class="iz-datetime">{{ label }}</span>
</template>

<style scoped>
.iz-datetime { font-variant-numeric: tabular-nums; }
</style>
