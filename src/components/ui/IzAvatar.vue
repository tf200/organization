<script setup lang="ts">
/** Replacement for NcAvatar. Initial-only; the theme has no image avatar. */
import { computed } from 'vue'

const props = withDefaults(defineProps<{
	displayName?: string
	size?: number
	soft?: boolean
}>(), { displayName: '', size: 36, soft: false })

const initial = computed(() => (props.displayName || '?').charAt(0).toUpperCase())
</script>

<template>
	<span class="iz-identity__avatar"
		:class="{ 'iz-identity__avatar--soft': soft }"
		:style="{ width: size + 'px', height: size + 'px', fontSize: Math.round(size * 0.42) + 'px' }"
		aria-hidden="true">{{ initial }}</span>
</template>
