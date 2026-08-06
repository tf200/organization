<template>
	<IzModal v-if="show"
		title="Create New Organization"
		size="large"
		class="create-org-modal"
		@close="closeModal">
		<div class="modal-content">
			<div class="modal-body-grid">
				<!-- Left Column: Identity & Contact -->
				<div class="grid-column">
					<!-- Organization Details -->
					<div class="form-section">
						<div class="section-header">
							<AccountGroup :size="20" class="section-icon" />
							<h3>Organization Details</h3>
						</div>
						<div class="section-body">
							<IzTextField v-model="newOrg.displayname"
								label="Organization Name"
								:error="!!errors.displayname"
								:helper-text="errors.displayname"
								required
								class="full-width" />
						</div>
					</div>

					<!-- Contact Information -->
					<div class="form-section">
						<div class="section-header">
							<CardAccountDetails :size="20" class="section-icon" />
							<h3>Contact Information</h3>
						</div>
						<div class="section-body grid-2-tight">
							<IzTextField v-model="newOrg.contactFirstName"
								label="First Name" />
							<IzTextField v-model="newOrg.contactLastName"
								label="Last Name" />
							<IzTextField v-model="newOrg.contactEmail"
								label="Email"
								type="email">
								<template #leading-icon>
									<Email :size="16" />
								</template>
							</IzTextField>
							<IzTextField v-model="newOrg.contactPhone"
								label="Phone"
								type="tel">
								<template #leading-icon>
									<Phone :size="16" />
								</template>
							</IzTextField>
						</div>
					</div>

					<!-- Organization Admin -->
					<div class="form-section">
						<div class="section-header">
							<AccountGroup :size="20" class="section-icon" />
							<h3>Organization Admin</h3>
						</div>
						<div class="section-body grid-2-tight">
							<IzTextField v-model="newOrg.adminUserId"
								label="Admin User ID"
								:error="!!errors.adminUserId"
								:helper-text="errors.adminUserId"
								required />
							<IzTextField v-model="newOrg.adminDisplayName"
								label="Admin Display Name" />
							<IzTextField v-model="newOrg.adminEmail"
								label="Admin Email"
								type="email" />
							<IzTextField v-model="newOrg.adminPassword"
								label="Admin Password"
								type="password"
								:error="!!errors.adminPassword"
								:helper-text="errors.adminPassword"
								required />
						</div>
					</div>
				</div>

				<!-- Right Column: Plan & Limits -->
				<div class="grid-column">
					<!-- Subscription Plan -->
					<div class="form-section">
						<div class="section-header">
							<Briefcase :size="20" class="section-icon" />
							<h3>Billing & Plan</h3>
						</div>
						<div class="section-body">
							<div class="trial-checkbox-row">
								<label class="trial-checkbox-label">
									<input type="checkbox" :checked="newOrg.isTrial" @change="onTrialToggle">
									<span>Create as Trial Organization</span>
								</label>
								<div v-if="newOrg.isTrial" class="trial-summary">
									<span class="trial-chip">Trial</span>
									<span>7 days &middot; 3 members &middot; 1 project &middot; 100MB storage</span>
								</div>
							</div>
							<template v-if="!newOrg.isTrial">
								<div class="form-row">
									<label class="nc-label-text">Subscription Plan</label>
									<div class="select-wrapper">
										<select v-model="newOrg.planId" class="nc-select-native" @change="onPlanChange">
											<option :value="null">
												Custom Plan
											</option>
											<option v-for="plan in plans" :key="plan.id" :value="plan.id">
												{{ plan.name }}
											</option>
										</select>
									</div>
								</div>
								<div class="form-row">
									<label class="nc-label-text">Validity Period</label>
									<div class="select-wrapper">
										<select v-model="newOrg.validity" class="nc-select-native">
											<option value="1 month">
												1 Month
											</option>
											<option value="1 year">
												1 Year
											</option>
										</select>
									</div>
								</div>
							</template>
						</div>
					</div>

					<!-- Resource Allocation -->
					<div v-if="!newOrg.isTrial" class="form-section">
						<div class="section-header">
							<Database :size="20" class="section-icon" />
							<h3>Resource Allocation</h3>
						</div>
						<div class="section-body grid-2-tight">
							<IzTextField v-model.number="newOrg.memberLimit"
								label="Max Members"
								type="number" />
							<IzTextField v-model.number="newOrg.projectsLimit"
								label="Max Projects"
								type="number" />
							<IzTextField v-model.number="sharedStorageGB"
								label="Shared Storage (GB)"
								type="number"
								:min="0" />
							<IzTextField v-model.number="privateStorageGB"
								label="Private Storage (GB)"
								type="number"
								:min="0" />
						</div>
					</div>
				</div>
			</div>

			<div class="modal-actions">
				<IzButton type="tertiary" @click="closeModal">
					Cancel
				</IzButton>
				<IzButton type="primary" :disabled="submitting" @click="handleCreate">
					<template v-if="submitting" #icon>
						<IzSpinner :size="20" />
					</template>
					{{ submitting ? 'Creating...' : 'Create Organization' }}
				</IzButton>
			</div>
		</div>
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

// Icons
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import CardAccountDetails from 'vue-material-design-icons/CardAccountDetails.vue'
import Briefcase from 'vue-material-design-icons/Briefcase.vue'
import Database from 'vue-material-design-icons/Database.vue'
import Email from 'vue-material-design-icons/Email.vue'
import Phone from 'vue-material-design-icons/Phone.vue'

const props = defineProps<{
	show: boolean
	plans: any[]
}>()

const emit = defineEmits(['close', 'success'])

const submitting = ref(false)

const errors = reactive({
	displayname: '',
	adminUserId: '',
	adminPassword: '',
})

const defaultNewOrg = {
	displayname: '',
	contactFirstName: '',
	contactLastName: '',
	contactEmail: '',
	contactPhone: '',
	adminUserId: '',
	adminPassword: '',
	adminDisplayName: '',
	adminEmail: '',
	validity: '1 year',
	planId: null,
	memberLimit: 10,
	projectsLimit: 5,
	sharedStoragePerProject: 1073741824, // 1GB
	privateStorage: 5368709120, // 5GB
	price: 0,
	currency: 'EUR',
	isTrial: false,
}

const newOrg = reactive({ ...defaultNewOrg })

const onTrialToggle = (e: Event) => {
	newOrg.isTrial = (e.target as HTMLInputElement).checked
}

// Computed properties for storage conversion (Bytes <-> GB)
const sharedStorageGB = computed({
	get: () => parseFloat((newOrg.sharedStoragePerProject / (1024 ** 3)).toFixed(2)),
	set: (val) => {
		newOrg.sharedStoragePerProject = Math.round(val * (1024 ** 3))
	},
})

const privateStorageGB = computed({
	get: () => parseFloat((newOrg.privateStorage / (1024 ** 3)).toFixed(2)),
	set: (val) => {
		newOrg.privateStorage = Math.round(val * (1024 ** 3))
	},
})

watch(() => props.show, (val) => {
	if (val) {
		Object.assign(newOrg, defaultNewOrg)
		errors.displayname = ''
		errors.adminUserId = ''
		errors.adminPassword = ''
	}
})

const closeModal = () => {
	emit('close')
}

const onPlanChange = () => {
	if (newOrg.planId) {
		const plan = props.plans.find(p => p.id === newOrg.planId)
		if (plan) {
			// Future: Logic to populate limits from plan
		}
	}
}

const handleCreate = async () => {
	errors.displayname = !newOrg.displayname ? 'Name is required' : ''
	errors.adminUserId = !newOrg.adminUserId ? 'Admin user ID is required' : ''
	errors.adminPassword = !newOrg.adminPassword ? 'Admin password is required' : ''

	if (errors.displayname || errors.adminUserId || errors.adminPassword) return

	submitting.value = true
	try {
		await confirmPassword()
		// The server accepts `trial` as an alias alongside `isTrial`; both are
		// sent when creating a trial, matching the previous behaviour.
		const payload: Record<string, unknown> = { ...newOrg }
		if (newOrg.isTrial) {
			payload.trial = true
		}
		await axios.post(generateOcsUrl('apps/organization/organizations'), payload)
		emit('success')
		closeModal()
	} catch (error) {
		console.error('Failed to create organization', error)
	} finally {
		submitting.value = false
	}
}
</script>

<style scoped>
.modal-content {
	display: flex;
	flex-direction: column;
	gap: 24px;
	padding: 8px 4px;
}

.trial-checkbox-row {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px 16px;
	margin-bottom: 12px;
	background: color-mix(in srgb, var(--color-primary) 8%, transparent);
	border: 1px solid color-mix(in srgb, var(--color-primary) 25%, var(--color-border));
	border-radius: var(--border-radius-large);
}

.trial-summary {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 0.85rem;
	color: var(--color-text-light);
}

.trial-checkbox-label {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
	font-weight: 600;
	user-select: none;
}

.trial-checkbox-label input[type="checkbox"] {
	width: 18px;
	height: 18px;
	cursor: pointer;
}

.trial-chip {
	padding: 2px 8px;
	border-radius: 999px;
	background: var(--color-primary);
	color: var(--color-primary-text);
	font-size: 0.7rem;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
}

/* Grid Layout */
.modal-body-grid {
	display: grid;
	grid-template-columns: 1fr;
	gap: 24px;
}

@media (min-width: 900px) {
	.modal-body-grid {
		grid-template-columns: 1fr 1fr;
		gap: 32px;
	}
}

.grid-column {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.grid-2-tight {
	display: grid;
	grid-template-columns: 1fr;
	gap: 12px;
}

@media (min-width: 600px) {
	.grid-2-tight {
		grid-template-columns: 1fr 1fr;
	}
}

/* Sections */
.form-section {
	background-color: var(--color-background-translucent);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	transition: box-shadow 0.2s ease;
}

.form-section:hover {
	box-shadow: var(--iz-shadow);
}

.section-header {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-bottom: 20px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 12px;
}

.section-icon {
	color: var(--color-primary);
	display: flex;
	align-items: center;
}

.section-header h3 {
	margin: 0;
	font-size: 1.1em;
	font-weight: 700;
	color: var(--color-main-text);
}

.section-body {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

/* Form Elements */
.full-width {
	width: 100%;
}

.form-row {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.nc-label-text {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	margin-left: 2px;
}

.select-wrapper {
	position: relative;
}

.nc-select-native {
	width: 100%;
	padding: 8px 32px 8px 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 1em;
	line-height: 1.5;
	height: auto;
	transition: border-color 0.2s;
	appearance: none;
	-webkit-appearance: none;
	background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' width='16' height='16'%3E%3Cpath fill='none' stroke='%23888' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
	background-repeat: no-repeat;
	background-position: right 12px center;
	cursor: pointer;
}

.nc-select-native:focus {
	border-color: var(--color-primary);
	outline: 2px solid var(--color-primary-element);
	outline-offset: -1px;
}

/* Actions */
.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 16px;
	margin-top: 8px;
	padding-top: 24px;
	border-top: 1px solid var(--color-border);
}

/* Mobile optimizations */
@media (max-width: 600px) {
	.modal-body-grid {
		gap: 16px;
	}

	.form-section {
		padding: 16px;
	}
}
</style>
