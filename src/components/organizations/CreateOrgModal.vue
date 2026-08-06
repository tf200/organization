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
							<div class="iz-inset trial-block">
								<label class="trial-toggle">
									<input type="checkbox" :checked="newOrg.isTrial" @change="onTrialToggle">
									<span>Create as trial organization</span>
								</label>
								<div v-if="newOrg.isTrial" class="trial-block__summary">
									<span class="iz-badge iz-badge--cat-5">Trial</span>
									<span v-if="trialSummary">{{ trialSummary }}</span>
									<span v-else class="iz-state">Reading the current trial defaults…</span>
								</div>
							</div>
							<template v-if="!newOrg.isTrial">
								<div class="form-row">
									<label class="iz-label">Subscription Plan</label>
									<div class="select-wrapper">
										<select v-model="newOrg.planId" class="iz-select" @change="onPlanChange">
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
									<label class="iz-label">Validity Period</label>
									<div class="select-wrapper">
										<select v-model="newOrg.validity" class="iz-select">
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
		</div>

		<template #footer>
			<IzButton type="tertiary" @click="closeModal">
				Cancel
			</IzButton>
			<IzButton type="primary" :disabled="submitting" @click="handleCreate">
				<template v-if="submitting" #icon>
					<IzSpinner :size="20" />
				</template>
				{{ submitting ? 'Creating...' : 'Create Organization' }}
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
import { ocs } from '../../lib/api'
import { formatFileSize } from '../../lib/format'
import type { TrialSettings } from '../../types'

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

/**
 * The trial summary used to be the hardcoded string
 * "7 days · 3 members · 1 project · 100MB storage". It did not read the
 * settings, so it went stale the moment anyone changed a default — and it was
 * only ever right because nobody had saved them.
 */
const trialDefaults = ref<TrialSettings | null>(null)

const trialSummary = computed(() => {
	const d = trialDefaults.value
	if (!d) return null
	const members = `${d.maxMembers} member${d.maxMembers === 1 ? '' : 's'}`
	const projects = `${d.maxProjects} project${d.maxProjects === 1 ? '' : 's'}`
	return `${d.duration} · ${members} · ${projects} · ${formatFileSize(d.sharedStoragePerProject)} shared per project`
})

async function loadTrialDefaults() {
	try {
		trialDefaults.value = await ocs<TrialSettings>('admin/settings/trial')
	} catch {
		// Non-fatal: the line is omitted rather than shown wrong.
		trialDefaults.value = null
	}
}

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
	if (val) loadTrialDefaults()
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
.trial-block {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: var(--iz-gap-tight, 10px);
}

/* The theme gives .iz-app input[type=checkbox] its accent-color, so the only
   thing left here is the row layout. */
.trial-toggle {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
	user-select: none;
	font-size: var(--iz-fs-md);
	font-weight: 600;
}

.trial-toggle input[type='checkbox'] {
	width: 16px;
	height: 16px;
	cursor: pointer;
}

.trial-block__summary {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: var(--iz-fs-sm);
	color: var(--iz-text-secondary);
}
</style>
