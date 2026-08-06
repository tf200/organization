<script setup lang="ts">
/**
 * The expand/collapse chevron for `.iz-row--expandable`.
 *
 * The theme's contract (USING-THE-THEME.md §7) fixes all of this: chevron
 * pointing down, last element in `.iz-row__actions`, rotating 180° when open,
 * muted until the header is hovered. Size, colour, rotation and the
 * reduced-motion opt-out all come from `.iz-row__chevron`; nothing here is
 * styled locally.
 *
 * `stroke="currentColor"` rather than a literal — a hardcoded stroke in a
 * template SVG sits outside every stylesheet and has escaped every colour
 * sweep so far (§9).
 */
defineProps<{ open?: boolean }>()
</script>

<template>
	<svg class="iz-row__chevron"
		:class="{ 'iz-row__chevron--open': open }"
		width="14"
		height="14"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		stroke-width="2.5"
		stroke-linecap="round"
		stroke-linejoin="round"
		aria-hidden="true">
		<polyline points="6 9 12 15 18 9" />
	</svg>
</template>
