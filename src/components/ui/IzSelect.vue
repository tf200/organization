<script setup lang="ts">
/**
 * Replacement for NcSelect, for the simple single-select case this app uses.
 * Options are `{ id, label }`, matching the shape the handover picker builds.
 */
withDefaults(defineProps<{
	modelValue?: string | number | null
	options?: { id: string | number; label: string }[]
	placeholder?: string
	disabled?: boolean
	inputId?: string
	ariaLabel?: string
}>(), {
	modelValue: null,
	options: () => [],
	placeholder: 'Select…',
	disabled: false,
	inputId: undefined,
	ariaLabel: undefined,
})

const emit = defineEmits<{ 'update:modelValue': [string | number | null] }>()
</script>

<template>
	<select :id="inputId"
		class="iz-select"
		:disabled="disabled"
		:aria-label="ariaLabel"
		:value="modelValue ?? ''"
		@change="emit('update:modelValue', ($event.target as HTMLSelectElement).value || null)">
		<option value="" disabled>
			{{ placeholder }}
		</option>
		<option v-for="opt in options" :key="opt.id" :value="opt.id">
			{{ opt.label }}
		</option>
	</select>
</template>
