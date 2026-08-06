<script setup lang="ts">
/**
 * Replacement for NcTextField. Prop surface kept close to the original so the
 * swap in existing templates is mechanical: label, type, error, helperText,
 * placeholder, required, disabled.
 */
import { useId } from 'vue'

withDefaults(defineProps<{
	modelValue?: string | number
	label?: string
	type?: string
	placeholder?: string
	required?: boolean
	disabled?: boolean
	error?: boolean
	helperText?: string
	min?: number | string
	step?: number | string
}>(), {
	modelValue: '',
	label: '',
	type: 'text',
	placeholder: undefined,
	required: false,
	disabled: false,
	error: false,
	helperText: '',
	min: undefined,
	step: undefined,
})

const emit = defineEmits<{ 'update:modelValue': [string | number] }>()
const id = useId()

function onInput(e: Event) {
	const el = e.target as HTMLInputElement
	emit('update:modelValue', el.type === 'number' ? el.valueAsNumber : el.value)
}
</script>

<template>
	<div class="iz-field">
		<label v-if="label" class="iz-label" :for="id">{{ label }}</label>
		<input :id="id"
			class="iz-input"
			:type="type"
			:value="modelValue"
			:placeholder="placeholder"
			:required="required"
			:disabled="disabled"
			:min="min"
			:step="step"
			:aria-invalid="error || undefined"
			@input="onInput">
		<p v-if="helperText" class="iz-field__help" :class="{ 'iz-field__help--error': error }">
			{{ helperText }}
		</p>
	</div>
</template>

<style scoped>
/* Layout only. */
.iz-field {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.iz-field__help {
	margin: 5px 0 0;
	font-size: var(--iz-fs-xs);
	color: var(--iz-text-muted);
}

.iz-field__help--error {
	color: var(--iz-danger-text);
}
</style>
