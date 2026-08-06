<script setup lang="ts">
/**
 * Plain-element replacement for NcButton.
 *
 * The theme's .iz-btn primitive targets bare <button>. Nextcloud core
 * explicitly excludes `.button-vue` from its own button rules, so NcButton
 * lives outside the primitive entirely and can only be restyled with :deep()
 * overrides in every consumer. A plain button is what the theme is built for.
 *
 * Prop surface mirrors NcButton's so the swap in existing templates is
 * mechanical: `type` maps onto the theme's tone modifiers.
 */
withDefaults(defineProps<{
	type?: 'primary' | 'secondary' | 'tertiary' | 'error' | 'warning'
	disabled?: boolean
	nativeType?: 'button' | 'submit'
	title?: string
	ariaLabel?: string
	wide?: boolean
}>(), {
	type: 'secondary',
	disabled: false,
	nativeType: 'button',
	title: undefined,
	ariaLabel: undefined,
	wide: false,
})

const TONE: Record<string, string> = {
	primary: 'iz-btn--primary',
	secondary: '',
	tertiary: 'iz-btn--plain',
	error: 'iz-btn--danger',
	warning: 'iz-btn--accent',
}
</script>

<template>
	<button class="iz-btn iz-btn--sm"
		:class="[TONE[type], { 'iz-button--wide': wide }]"
		:type="nativeType"
		:disabled="disabled"
		:title="title"
		:aria-label="ariaLabel">
		<slot name="icon" />
		<slot />
	</button>
</template>

<style scoped>
/* Layout only. */
.iz-button--wide {
	width: 100%;
	justify-content: center;
}
</style>
