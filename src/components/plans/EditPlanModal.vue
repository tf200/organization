<template>
	<IzModal v-if="show"
		title="Edit Plan"
		size="large"
		class="edit-plan-modal"
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
				{{ submitting ? 'Saving...' : 'Save Changes' }}
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
	plan: any | null
}>()

const emit = defineEmits(['close', 'success'])

const submitting = ref(false)
const errors = reactive({
	name: '',
})

const form = reactive({
	name: '',
	maxProjects: 0,
	maxMembers: 0,
	sharedStoragePerProject: 0,
	privateStoragePerUser: 0,
	price: 0,
	currency: 'EUR',
	isPublic: true,
})

const sharedStorageGB = computed({
	get: () => parseFloat((form.sharedStoragePerProject / (1024 ** 3)).toFixed(2)),
	set: (val) => {
		form.sharedStoragePerProject = Math.round(val * (1024 ** 3))
	},
})

const privateStorageGB = computed({
	get: () => parseFloat((form.privateStoragePerUser / (1024 ** 3)).toFixed(2)),
	set: (val) => {
		form.privateStoragePerUser = Math.round(val * (1024 ** 3))
	},
})

watch(() => props.show, (val) => {
	if (val && props.plan) {
		Object.assign(form, {
			name: props.plan.name,
			maxProjects: props.plan.maxProjects,
			maxMembers: props.plan.maxMembers,
			sharedStoragePerProject: props.plan.sharedStoragePerProject,
			privateStoragePerUser: props.plan.privateStoragePerUser,
			price: props.plan.price || 0,
			currency: props.plan.currency || 'EUR',
			isPublic: props.plan.isPublic,
		})
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
		await axios.put(generateOcsUrl('apps/organization/plans/' + props.plan.id), form)
		emit('success', { ...props.plan, ...form })
		closeModal()
	} catch (error) {
		if (error !== 'cancelled') {
			console.error('Failed to update plan', error)
		}
	} finally {
		submitting.value = false
	}
}
</script>

<style scoped>
</style>
