<script setup lang="ts">
/**
 * The one confirmation dialog. No alert() or confirm() anywhere in this app —
 * native dialogs cannot be themed and cannot hold an error.
 *
 * Contract, matching the vendored copy in adminpage and superadminpage:
 * the PARENT owns `busy` and `error`, and this dialog NEVER closes itself.
 * A failed action therefore stays open with its reason attached, instead of
 * vanishing and leaving the user to guess.
 *
 * NOTE: this is a Vue 3 port, not a copy. The shared file is a Vue 2 SFC that
 * a Vue 3 app cannot import, so a change to the shared dialog has to be
 * ported here rather than copied. See this app's CLAUDE.md.
 */
import { onBeforeUnmount, onMounted, useTemplateRef } from 'vue'

const props = withDefaults(defineProps<{
	title: string
	message?: string
	confirmLabel?: string
	cancelLabel?: string
	busyLabel?: string
	danger?: boolean
	alertOnly?: boolean
	busy?: boolean
	error?: string
}>(), {
	message: '',
	confirmLabel: 'Confirm',
	cancelLabel: 'Cancel',
	busyLabel: 'Working…',
	danger: false,
	alertOnly: false,
	busy: false,
	error: '',
})

const emit = defineEmits<{ confirm: []; cancel: [] }>()

const confirmButton = useTemplateRef<HTMLButtonElement>('confirmButton')

function cancel() {
	if (props.busy) return // never abandon an in-flight action
	emit('cancel')
}

function onKeydown(e: KeyboardEvent) {
	if (e.key === 'Escape') {
		e.stopPropagation()
		cancel()
	}
}

onMounted(() => {
	confirmButton.value?.focus()
	document.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))
</script>

<template>
	<div class="iz-modal-backdrop" @click.self="cancel">
		<div class="iz-modal confirm-dialog" role="dialog" aria-modal="true" :aria-label="title">
			<div class="iz-modal__header">
				<h4 class="iz-panel__title">{{ title }}</h4>
				<button
					class="iz-close iz-close--sm"
					type="button"
					:disabled="busy"
					aria-label="Close"
					@click="cancel">&times;</button>
			</div>

			<div class="iz-modal__body">
				<p v-if="message" class="iz-modal__confirm-text">{{ message }}</p>
				<slot />
				<div v-if="error" class="iz-error" role="alert">{{ error }}</div>
			</div>

			<div class="iz-modal__footer">
				<button
					v-if="!alertOnly"
					class="iz-btn"
					type="button"
					:disabled="busy"
					@click="cancel">{{ cancelLabel }}</button>
				<button
					ref="confirmButton"
					class="iz-btn"
					:class="danger ? 'iz-btn--danger' : 'iz-btn--primary'"
					type="button"
					:disabled="busy"
					@click="emit('confirm')">
					<span v-if="busy" class="iz-spinner" aria-hidden="true"></span>
					{{ busy ? busyLabel : confirmLabel }}
				</button>
			</div>
		</div>
	</div>
</template>

<style scoped>
/* Layout only. The theme deliberately sets no width on .iz-modal —
   width is the caller's responsibility. */
.confirm-dialog {
	max-width: 460px;
	width: 100%;
}
</style>
