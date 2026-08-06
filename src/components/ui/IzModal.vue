<script setup lang="ts">
/**
 * Replacement for NcModal, built on the theme's .iz-modal primitives.
 *
 * NcModal supplied Escape and click-outside for free; both are re-implemented
 * here so no behaviour is lost. `size` maps onto a max-width because the theme
 * deliberately sets none — width is the caller's layout responsibility.
 */
import { onBeforeUnmount, onMounted } from 'vue'

const props = withDefaults(defineProps<{
	title?: string
	size?: 'small' | 'normal' | 'large'
}>(), { title: '', size: 'normal' })

const emit = defineEmits<{ close: [] }>()

const WIDTH: Record<string, string> = {
	small: '460px',
	normal: '640px',
	large: '880px',
}

function onKeydown(e: KeyboardEvent) {
	if (e.key === 'Escape') {
		e.stopPropagation()
		emit('close')
	}
}

onMounted(() => document.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))
</script>

<template>
	<div class="iz-modal-backdrop iz-modal-scroll" @click.self="emit('close')">
		<div class="iz-modal"
			role="dialog"
			aria-modal="true"
			:aria-label="props.title || undefined"
			:style="{ maxWidth: WIDTH[props.size] }">
			<div v-if="props.title" class="iz-modal__header">
				<h4 class="iz-panel__title">
					{{ props.title }}
				</h4>
				<button class="iz-close"
					type="button"
					aria-label="Close"
					@click="emit('close')">
					&times;
				</button>
			</div>
			<div class="iz-modal__body">
				<slot />
			</div>
			<div v-if="$slots.footer" class="iz-modal__footer">
				<slot name="footer" />
			</div>
		</div>
	</div>
</template>

<style scoped>
/* Layout only. */
.iz-modal-scroll {
	overflow-y: auto;
}

.iz-modal {
	width: 100%;
	max-height: 90vh;
}
</style>
