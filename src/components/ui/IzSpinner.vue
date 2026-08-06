<script setup lang="ts">
/**
     Replacement for NcLoadingIcon. `.iz-spinner` inherits currentColor, so it
    works inside a button with no extra rules.
 */
withDefaults(defineProps<{ size?: number }>(), { size: 20 })
</script>

<template>
	<span class="iz-spinner"
		:class="{ 'iz-spinner--sm': size <= 12, 'iz-spinner--lg': size >= 32 }"
		aria-hidden="true" />
</template>
