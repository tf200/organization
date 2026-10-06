<template>
	<IzModal v-if="show"
		title="Create New Plan"
		size="large"
		class="create-plan-modal"
		@close="closeModal">
		<div class="modal-content">
			<div class="modal-body-grid">
				<!-- Section 1: Plan Details -->
				<div class="grid-column">
					<div class="form-section">
						<div class="section-header">
							<CardAccountDetails :size="20" class="section-icon" />
							<h3>Plan Details</h3>
						</div>
						<div class="section-body">
							<IzTextField v-model="form.name"
								label="Plan Name"
								:error="!!errors.name"
								:helper-text="errors.name"
								required
								class="full-width" />

							<div class="form-row">
								<label class="iz-label">Visibility</label>
								<div class="select-wrapper">
									<select v-model="form.isPublic" class="iz-select">
										<option :value="true">
											Public
										</option>
										<option :value="false">
											Private
										</option>
									</select>
								</div>
							</div>
						</div>
					</div>

					<!-- Section 3: Pricing -->
					<div class="form-section">
						<div class="section-header">
							<CurrencyUsd :size="20" class="section-icon" />
							<h3>Pricing</h3>
						</div>
						<div class="section-body grid-2-tight">
							<IzTextField v-model.number="form.price"
								label="Price"
								type="number"
								step="0.01"
								:min="0" />

							<div class="form-row">
								<label class="iz-label">Currency</label>
								<div class="select-wrapper">
									<select v-model="form.currency" class="iz-select">
										<option value="EUR">
											EUR
										</option>
										<option value="USD">
											USD
										</option>
										<option value="GBP">
											GBP
										</option>
									</select>
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Section 2: Resource Limits -->
				<div class="grid-column">
					<div class="form-section">
						<div class="section-header">
							<Database :size="20" class="section-icon" />
							<h3>Resource Limits</h3>
						</div>
						<div class="section-body grid-2-tight">
							<IzTextField v-model.number="form.maxMembers"
								label="Max Members"
								type="number"
								:min="1" />
							<IzTextField v-model.number="form.maxProjects"
								label="Max Projects"
								type="number"
								:min="1" />
							<IzTextField v-model.number="sharedStorageGB"
								label="Shared Storage (GB)"
								type="number"
								:min="0.001" />
							<IzTextField v-model.number="privateStorageGB"
								label="Private Storage (GB)"
								type="number"
								:min="0.001" />
							<IzTextField v-model="externalStorageGB"
								label="Storage per External (GB)"
								type="number"
								:min="0"
								placeholder="1"
								helper-text="Private storage of each external collaborator. Empty uses the default of 1 GB." />
						</div>
					</div>
				</div>
			</div>
		</div>

		<template #footer>
			<IzButton type="tertiary" @click="closeModal">
				Cancel
			</IzButton>
			<IzButton type="primary" :disabled="submitting" @click="handleSubmit">
				<template v-if="submitting" #icon>
					<IzSpinner :size="20" />
				</template>
				{{ submitting ? 'Creating...' : 'Create Plan' }}
			</IzButton>
		</template>
	</IzModal>
</template>

<script setup lang="ts">
import IzModal from '../ui/IzModal.vue'
import IzTextField from '../ui/IzTextField.vue'
import IzButton from '../ui/IzButton.vue'
import IzSpinner from '../ui/IzSpinner.vue'
import { ref, reactive, watch, computed } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { confirmPassword } from '../../lib/passwordConfirmation'

import CardAccountDetails from 'vue-material-design-icons/CardAccountDetails.vue'
import Database from 'vue-material-design-icons/Database.vue'
import CurrencyUsd from 'vue-material-design-icons/CurrencyUsd.vue'

const props = defineProps<{
	show: boolean
}>()

const emit = defineEmits(['close', 'success'])

const submitting = ref(false)
const errors = reactive({
	name: '',
})

const defaultForm = {
	name: '',
	maxProjects: 10,
	maxMembers: 10,
	sharedStoragePerProject: 1073741824, // 1GB
	privateStoragePerUser: 5368709120, // 5GB
	price: 0,
	currency: 'EUR',
	isPublic: true,
	externalStorageQuota: null as number | null,
}

const form = reactive({ ...defaultForm })

const sharedStorageGB = computed({
	get: () => parseFloat((form.sharedStoragePerProject / (1024 ** 3)).toFixed(2)),
	set: (val) => {
		form.sharedStoragePerProject = Math.round(val * (1024 ** 3))
	},
})

// Empty means the instance default for external collaborators.
const externalStorageGB = computed({
	get: () => form.externalStorageQuota === null ? '' : String(parseFloat((form.externalStorageQuota / (1024 ** 3)).toFixed(2))),
	set: (val: string | number) => {
		const gb = String(val).trim() === '' ? NaN : Number(val)
		form.externalStorageQuota = Number.isFinite(gb) && gb >= 0 ? Math.round(gb * (1024 ** 3)) : null
	},
})

const privateStorageGB = computed({
	get: () => parseFloat((form.privateStoragePerUser / (1024 ** 3)).toFixed(2)),
	set: (val) => {
		form.privateStoragePerUser = Math.round(val * (1024 ** 3))
	},
})

watch(() => props.show, (val) => {
	if (val) {
		Object.assign(form, defaultForm)
		errors.name = ''
	}
})

const closeModal = () => {
	emit('close')
}

const handleSubmit = async () => {
	errors.name = !form.name ? 'Name is required' : ''
	if (errors.name) return

	submitting.value = true
	try {
		await confirmPassword()
		await axios.post(generateOcsUrl('apps/organization/plans'), form)
		emit('success')
		closeModal()
	} catch (error) {
		if (error !== 'cancelled') {
			console.error('Failed to create plan', error)
		}
	} finally {
		submitting.value = false
	}
}
</script>

<style scoped>
</style>
