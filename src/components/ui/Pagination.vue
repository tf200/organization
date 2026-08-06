<script setup lang="ts">
/**
 * Offset pagination on the theme's .iz-pagination primitive.
 *
 * The primitive's own comment notes it was reimplemented five times across
 * these apps and this is the one — so this component exists to stop a sixth.
 *
 * Total is optional because none of the organization endpoints return one:
 * they answer `{items, limit, offset}` only. Without a total we cannot know
 * the last page, so the control degrades to prev/next and reports the range
 * rather than "page 3 of 7". `hasMore` is inferred by the caller from a full
 * page of results.
 */
import { computed } from 'vue'

const props = withDefaults(defineProps<{
	offset: number
	limit: number
	/** Items returned for the current page — used to infer whether more exist. */
	count: number
	/** Only if the endpoint reports one. */
	total?: number | null
	label?: string
	disabled?: boolean
}>(), { total: null, label: 'items', disabled: false })

const emit = defineEmits<{ 'update:offset': [number] }>()

const first = computed(() => (props.count === 0 ? 0 : props.offset + 1))
const last = computed(() => props.offset + props.count)

const hasPrev = computed(() => props.offset > 0)
/* A short page means the end; a full one means there is probably another. */
const hasNext = computed(() =>
	props.total !== null && props.total !== undefined
		? last.value < props.total
		: props.count === props.limit)

const rangeLabel = computed(() => {
	if (props.count === 0) return `No ${props.label}`
	const of = props.total !== null && props.total !== undefined ? ` of ${props.total}` : ''
	return `${first.value}–${last.value}${of} ${props.label}`
})

function go(delta: number) {
	const next = Math.max(0, props.offset + delta * props.limit)
	if (next !== props.offset) emit('update:offset', next)
}
</script>

<template>
	<div v-if="hasPrev || hasNext" class="iz-pagination">
		<span>{{ rangeLabel }}</span>
		<div class="iz-pagination__pages">
			<button class="iz-btn"
				type="button"
				:disabled="!hasPrev || disabled"
				@click="go(-1)">
				Previous
			</button>
			<button class="iz-btn"
				type="button"
				:disabled="!hasNext || disabled"
				@click="go(1)">
				Next
			</button>
		</div>
	</div>
</template>

<!-- No <style>. .iz-pagination is entirely theme chrome. -->
