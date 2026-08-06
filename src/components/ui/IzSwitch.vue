<script setup lang="ts">
/**
     Replacement for NcCheckboxRadioSwitch type="switch". A real checkbox with
    a styled track, so it stays keyboard- and screen-reader-native.
 */
defineProps<{ modelValue: boolean; disabled?: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [boolean] }>()
</script>

<template>
	<label class="iz-switch" :class="{ 'iz-switch--disabled': disabled }">
		<input class="iz-switch__input"
			type="checkbox"
			:checked="modelValue"
			:disabled="disabled"
			@change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)">
		<span class="iz-switch__track" aria-hidden="true"><span class="iz-switch__knob" /></span>
		<span class="iz-switch__label"><slot /></span>
	</label>
</template>

<style scoped>
/* Layout plus the one control the theme has no primitive for.
   Colours all come from tokens. */
.iz-switch {
	display: flex;
	align-items: center;
	gap: 10px;
	font-size: var(--iz-fs-md);
	cursor: pointer;
	user-select: none;
}

.iz-switch--disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

/* Visually hidden but focusable — the ring lands on the track below. */
.iz-switch__input {
	position: absolute;
	opacity: 0;
	pointer-events: none;
	width: 0;
	height: 0;
}

.iz-switch__track {
	position: relative;
	width: 34px;
	height: 18px;
	border-radius: var(--iz-radius-pill);
	background: var(--iz-border-strong);
	flex-shrink: 0;
	transition: background var(--iz-transition);
}

.iz-switch__knob {
	position: absolute;
	top: 2px;
	left: 2px;
	width: 14px;
	height: 14px;
	border-radius: 50%;
	background: var(--iz-surface);
	transition: left var(--iz-transition);
}

.iz-switch__input:checked + .iz-switch__track {
	background: var(--iz-accent);
}

.iz-switch__input:checked + .iz-switch__track .iz-switch__knob {
	left: 18px;
}

.iz-switch__input:focus-visible + .iz-switch__track {
	box-shadow: 0 0 0 2px var(--iz-surface), 0 0 0 4px var(--iz-accent);
}

@media (prefers-reduced-motion: reduce) {
	.iz-switch__track,
	.iz-switch__knob { transition: none; }
}
</style>
