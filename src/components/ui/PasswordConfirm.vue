<script setup lang="ts">
/**
 * Themed password-confirmation prompt.
 *
 * Replaces @nextcloud/password-confirmation, which lists @nextcloud/vue as a
 * direct dependency and therefore pulled the whole component library into the
 * bundle even after every Nc* component was removed from this app's source.
 * superadminpage already talks to /login/confirm directly for the same reason.
 *
 * Same contract as ConfirmDialog: this component never closes itself, so a
 * wrong password keeps the prompt open with its reason attached.
 */
import { onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const emit = defineEmits<{ confirmed: []; cancelled: [] }>()

const password = ref('')
const busy = ref(false)
const error = ref('')
const field = useTemplateRef<HTMLInputElement>('field')

async function submit() {
	if (busy.value) return
	busy.value = true
	error.value = ''
	try {
		await axios.post(generateUrl('/login/confirm'), { password: password.value })
		emit('confirmed')
	} catch (e: unknown) {
		const err = e as { response?: { status?: number } }
		error.value = err.response?.status === 403
			? 'That password is not correct.'
			: 'Could not confirm your password. Please try again.'
	} finally {
		busy.value = false
	}
}

function cancel() {
	if (busy.value) return
	emit('cancelled')
}

function onKeydown(e: KeyboardEvent) {
	if (e.key === 'Escape') { e.stopPropagation(); cancel() }
}

onMounted(() => {
	field.value?.focus()
	document.addEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))
</script>

<template>
	<div class="iz-modal-backdrop" @click.self="cancel">
		<div class="iz-modal pw-confirm"
			role="dialog"
			aria-modal="true"
			aria-label="Confirm your password">
			<div class="iz-modal__header">
				<h4 class="iz-panel__title">
					Confirm your password
				</h4>
			</div>

			<form @submit.prevent="submit">
				<div class="iz-modal__body">
					<p class="iz-modal__confirm-text">
						This action changes organization data, so Nextcloud needs your password again.
					</p>

					<!-- Offscreen username: password managers need one adjacent to a
					     password field. A real a11y requirement, not decoration. -->
					<input class="iz-hidden-username"
						type="text"
						autocomplete="username"
						tabindex="-1"
						aria-hidden="true">

					<div>
						<label class="iz-label" for="pw-confirm-input">Password</label>
						<input id="pw-confirm-input"
							ref="field"
							v-model="password"
							class="iz-input"
							type="password"
							autocomplete="current-password"
							required>
					</div>

					<div v-if="error" class="iz-error" role="alert">
						{{ error }}
					</div>
				</div>

				<div class="iz-modal__footer">
					<button class="iz-btn"
						type="button"
						:disabled="busy"
						@click="cancel">
						Cancel
					</button>
					<button class="iz-btn iz-btn--primary" type="submit" :disabled="busy || !password">
						<span v-if="busy" class="iz-spinner" />
						{{ busy ? 'Confirming…' : 'Confirm' }}
					</button>
				</div>
			</form>
		</div>
	</div>
</template>

<style scoped>
/* Layout only. */
.pw-confirm {
	max-width: 420px;
	width: 100%;
}

.pw-confirm .iz-modal__body {
	display: flex;
	flex-direction: column;
	gap: var(--iz-gap);
}
</style>
