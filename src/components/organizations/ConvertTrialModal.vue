<template>
	<IzModal v-if="show"
		title="Convert Trial to Standard"
		size="large"
		class="convert-trial-modal"
		@close="closeModal">
		<div class="modal-content">
			<div class="modal-header">
				<div class="org-avatar">
					<IzAvatar :display-name="organization?.displayname"
						:size="64"
						:disable-tooltip="true" />
				</div>
				<div class="org-title">
					<h2>{{ organization?.displayname }}</h2>
					<span class="org-id">Convert Trial to Standard Organization</span>
				</div>
			</div>

			<div class="modal-body-grid">
				<!-- Left Column: Subscription Plan Selection -->
				<div class="grid-column">
					<div class="form-section">
						<div class="section-header">
							<Briefcase :size="20" class="section-icon" />
							<h3>Subscription Plan</h3>
						</div>
						<div class="section-body">
							<div class="form-row">
								<label class="nc-label-text">Standard Plan</label>
								<div class="select-wrapper">
									<select v-model="selectedPlanId" class="nc-select-native">
										<option :value="null" disabled>
											Select a standard plan...
										</option>
										<option v-for="plan in standardPlans" :key="plan.id" :value="plan.id">
											{{ plan.name }} ({{ plan.price }} {{ plan.currency }})
										</option>
									</select>
								</div>
							</div>

							<div class="form-row">
								<label class="nc-label-text">Validity Period</label>
								<div class="select-wrapper">
									<select v-model="validity" class="nc-select-native">
										<option value="1 month">
											1 Month
										</option>
										<option value="1 year">
											1 Year
										</option>
									</select>
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Right Column: Resource allocation preview -->
				<div class="grid-column">
					<div class="form-section">
						<div class="section-header">
							<Database :size="20" class="section-icon" />
							<h3>Resource Preview</h3>
						</div>
						<div v-if="selectedPlan" class="section-body grid-2-tight">
							<div class="kpi-preview-card">
								<span class="preview-label">Max Members</span>
								<span class="preview-value">{{ selectedPlan.maxMembers }}</span>
							</div>
							<div class="kpi-preview-card">
								<span class="preview-label">Max Projects</span>
								<span class="preview-value">{{ selectedPlan.maxProjects }}</span>
							</div>
							<div class="kpi-preview-card">
								<span class="preview-label">Shared Storage (Project)</span>
								<span class="preview-value">{{ formatFileSize(selectedPlan.sharedStoragePerProject) }}</span>
							</div>
							<div class="kpi-preview-card">
								<span class="preview-label">Private Storage (User)</span>
								<span class="preview-value">{{ formatFileSize(selectedPlan.privateStoragePerUser) }}</span>
							</div>
						</div>
						<div v-else class="section-body no-plan-selected">
							<p>Please select a plan to view resource allocations.</p>
						</div>
					</div>
				</div>
			</div>

			<div class="modal-actions">
				<IzButton type="tertiary" @click="closeModal">
					Cancel
				</IzButton>
				<IzButton type="primary"
					:disabled="submitting || !selectedPlanId"
					@click="handleConvert">
					<template v-if="submitting" #icon>
						<IzSpinner :size="20" />
					</template>
					{{ submitting ? 'Converting...' : 'Convert to Standard' }}
				</IzButton>
			</div>
		</div>
	</IzModal>
</template>

<script setup lang="ts">
import IzAvatar from '../ui/IzAvatar.vue'
import IzModal from '../ui/IzModal.vue'
import IzButton from '../ui/IzButton.vue'
import IzSpinner from '../ui/IzSpinner.vue'
import { ref, computed, watch } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { confirmPassword } from '../../lib/passwordConfirmation'

import Briefcase from 'vue-material-design-icons/Briefcase.vue'
import Database from 'vue-material-design-icons/Database.vue'

const props = defineProps<{
	show: boolean
	organization: any | null
	plans: any[]
}>()

const emit = defineEmits(['close', 'success'])

const submitting = ref(false)
const selectedPlanId = ref<number | null>(null)
const validity = ref('1 year')

const standardPlans = computed(() => {
	// Filter out the automatic trial plans which contain "Trial" in their name
	return props.plans.filter(p => !p.name?.toLowerCase().includes('trial'))
})

const selectedPlan = computed(() => {
	if (selectedPlanId.value === null) {
		return null
	}
	return props.plans.find(p => p.id === selectedPlanId.value) || null
})

watch(() => props.show, (val) => {
	if (val) {
		selectedPlanId.value = null
		validity.value = '1 year'
	}
})

const closeModal = () => {
	emit('close')
}

const formatFileSize = (bytes: number) => {
	if (!bytes || bytes === 0) return '0 B'
	const k = 1024
	const sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
	const i = Math.floor(Math.log(bytes) / Math.log(k))
	return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

const handleConvert = async () => {
	if (selectedPlanId.value === null || !props.organization) return

	submitting.value = true
	try {
		await confirmPassword()
		await axios.post(generateOcsUrl(`apps/organization/organizations/${props.organization.id}/convert-trial`), {
			planId: selectedPlanId.value,
			validity: validity.value,
		})
		emit('success')
		closeModal()
	} catch (error) {
		console.error('Failed to convert trial organization', error)
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

.modal-header {
	display: flex;
	align-items: center;
	gap: 16px;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
}

.org-avatar {
	flex-shrink: 0;
}

.org-title h2 {
	margin: 0;
	font-size: 1.4rem;
	font-weight: 600;
}

.org-id {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

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

.form-section {
	background-color: var(--color-background-translucent);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	transition: box-shadow 0.2s ease;
	height: 100%;
	box-sizing: border-box;
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

.kpi-preview-card {
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 16px;
	display: flex;
	flex-direction: column;
}

.preview-label {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	font-weight: 600;
	letter-spacing: 0.04em;
}

.preview-value {
	font-size: 1.25rem;
	font-weight: 700;
	margin-top: 4px;
	color: var(--color-main-text);
}

.no-plan-selected {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 120px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	text-align: center;
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 16px;
	margin-top: 8px;
	padding-top: 24px;
	border-top: 1px solid var(--color-border);
}
</style>
