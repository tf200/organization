<template>
	<IzModal v-if="show"
		title="Edit Organization"
		size="large"
		class="edit-org-modal"
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
					<span class="org-id">ID: {{ organization?.id }}</span>
				</div>
			</div>

			<div class="modal-body">
				<!-- Organization Identity -->
				<div class="form-section">
					<div class="section-header">
						<OfficeBuilding :size="20" class="section-icon" />
						<h3>Organization Identity</h3>
					</div>
					<div class="section-body">
						<IzTextField v-model="form.displayname"
							label="Organization Name"
							:helper-text="errors.displayname"
							:error="!!errors.displayname"
							required
							class="full-width">
							<template #leading-icon>
								<Domain :size="16" />
							</template>
						</IzTextField>
					</div>
				</div>

				<!-- Contact Information -->
				<div class="form-section">
					<div class="section-header">
						<CardAccountDetails :size="20" class="section-icon" />
						<h3>Contact Information</h3>
					</div>
					<div class="section-body grid-2">
						<IzTextField v-model="form.contactFirstName"
							label="First Name"
							placeholder="Contact person's first name">
							<template #leading-icon>
								<Account :size="16" />
							</template>
						</IzTextField>
						<IzTextField v-model="form.contactLastName"
							label="Last Name"
							placeholder="Contact person's last name">
							<template #leading-icon>
								<Account :size="16" />
							</template>
						</IzTextField>
						<IzTextField v-model="form.contactEmail"
							label="Email Address"
							type="email"
							placeholder="contact@company.com">
							<template #leading-icon>
								<Email :size="16" />
							</template>
						</IzTextField>
						<IzTextField v-model="form.contactPhone"
							label="Phone Number"
							type="tel"
							placeholder="+1 234 567 890">
							<template #leading-icon>
								<Phone :size="16" />
							</template>
						</IzTextField>
					</div>
				</div>
			</div>

			<div class="modal-actions">
				<IzButton type="tertiary" @click="closeModal">
					Cancel
				</IzButton>
				<IzButton type="primary"
					:disabled="saving"
					@click="handleSave">
					<template v-if="saving" #icon>
						<IzSpinner :size="20" />
					</template>
					{{ saving ? 'Saving...' : 'Save Changes' }}
				</IzButton>
			</div>
		</div>
	</IzModal>
</template>

<script setup lang="ts">
import IzAvatar from '../ui/IzAvatar.vue'
import IzModal from '../ui/IzModal.vue'
import IzTextField from '../ui/IzTextField.vue'
import IzButton from '../ui/IzButton.vue'
import IzSpinner from '../ui/IzSpinner.vue'
import { ref, reactive, watch } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import CardAccountDetails from 'vue-material-design-icons/CardAccountDetails.vue'
import Domain from 'vue-material-design-icons/Domain.vue'
import Account from 'vue-material-design-icons/Account.vue'
import Email from 'vue-material-design-icons/Email.vue'
import Phone from 'vue-material-design-icons/Phone.vue'

const props = defineProps<{
	show: boolean
	organization: any | null
}>()

const emit = defineEmits(['close', 'saved'])

const saving = ref(false)
const errors = reactive({
	displayname: '',
})

const form = reactive({
	displayname: '',
	contactFirstName: '',
	contactLastName: '',
	contactEmail: '',
	contactPhone: '',
})

watch(() => props.show, (val) => {
	if (val && props.organization) {
		Object.assign(form, {
			displayname: props.organization.displayname || '',
			contactFirstName: props.organization.contactFirstName || '',
			contactLastName: props.organization.contactLastName || '',
			contactEmail: props.organization.contactEmail || '',
			contactPhone: props.organization.contactPhone || '',
		})
		errors.displayname = ''
	}
})

const closeModal = () => {
	emit('close')
}

const validate = () => {
	errors.displayname = !form.displayname?.trim() ? 'Organization name is required' : ''
	return !errors.displayname
}

const handleSave = async () => {
	if (!validate()) return
	if (!props.organization) return

	saving.value = true
	try {
		const response = await axios.put(
			generateOcsUrl(`apps/organization/organizations/${props.organization.id}`),
			{ ...form },
		)
		emit('saved', response.data.ocs.data.organization)
		closeModal()
	} catch (error) {
		console.error('Failed to update organization', error)
	} finally {
		saving.value = false
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
	font-family: monospace;
}

.modal-body {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.form-section {
	background-color: var(--color-background-translucent);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	transition: box-shadow 0.2s ease;
}

.form-section:hover {
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
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

.grid-2 {
	display: grid;
	grid-template-columns: 1fr;
	gap: 16px;
}

@media (min-width: 600px) {
	.grid-2 {
		grid-template-columns: 1fr 1fr;
	}
}

.full-width {
	width: 100%;
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
