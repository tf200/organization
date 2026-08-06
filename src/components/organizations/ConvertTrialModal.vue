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
								<label class="iz-label">Standard Plan</label>
								<div class="select-wrapper">
									<select v-model="selectedPlanId" class="iz-select">
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
								<label class="iz-label">Validity Period</label>
								<div class="select-wrapper">
									<select v-model="validity" class="iz-select">
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
						<div v-if="selectedPlan" class="iz-metrics convert-preview">
							<div class="iz-metric">
								<span class="iz-metric__label">Max Members</span>
								<span class="iz-metric__value">{{ selectedPlan.maxMembers }}</span>
							</div>
							<div class="iz-metric">
								<span class="iz-metric__label">Max Projects</span>
								<span class="iz-metric__value">{{ selectedPlan.maxProjects }}</span>
							</div>
							<div class="iz-metric">
								<span class="iz-metric__label">Shared Storage (Project)</span>
								<span class="iz-metric__value">{{ formatFileSize(selectedPlan.sharedStoragePerProject) }}</span>
							</div>
							<div class="iz-metric">
								<span class="iz-metric__label">Private Storage (User)</span>
								<span class="iz-metric__value">{{ formatFileSize(selectedPlan.privateStoragePerUser) }}</span>
							</div>
						</div>
						<div v-else class="section-body">
							<p class="iz-state">
								Please select a plan to view resource allocations.
							</p>
						</div>
					</div>
				</div>
			</div>
		</div>

		<template #footer>
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
		</template>
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
.org-avatar {
	flex-shrink: 0;
}

.org-title h2 {
	margin: 0;
	font-family: 'Space Grotesk', system-ui, sans-serif;
	font-size: var(--iz-fs-lg);
	font-weight: 700;
	color: var(--iz-text);
}

.org-id {
	font-size: var(--iz-fs-xs);
	color: var(--iz-text-muted);
}

/* The metrics strip has no top rule inside a modal section. */
.convert-preview {
	border-top: 0;
	padding-top: 0;
}
</style>
