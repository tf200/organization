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
		</div>

		<template #footer>
			<div v-if="saveError" class="iz-error edit-org-modal__error" role="alert">
				{{ saveError }}
			</div>
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
		</template>
	</IzModal>
</template>

<script setup lang="ts">
import IzAvatar from '../ui/IzAvatar.vue'
import IzModal from '../ui/IzModal.vue'
import IzTextField from '../ui/IzTextField.vue'
import IzButton from '../ui/IzButton.vue'
import IzSpinner from '../ui/IzSpinner.vue'
import { ref, reactive, watch } from 'vue'
import { ocs } from '../../lib/api'

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
const saveError = ref('')
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
	saveError.value = ''
	try {
		const data = await ocs<{ organization: Record<string, unknown> }>(
			`organizations/${props.organization.id}`,
			{ method: 'PUT', body: { ...form } },
		)
		emit('saved', data?.organization)
		closeModal()
	} catch (e) {
		// Previously console.error only, so a rejected save left the modal
		// sitting there with the spinner stopped and no reason given.
		saveError.value = e instanceof Error ? e.message : String(e)
	} finally {
		saving.value = false
	}
}
</script>

<style scoped>
/* Layout only; .iz-error carries the chrome. */
.edit-org-modal__error {
	flex: 1;
	margin-right: auto;
}

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
	font-family: var(--iz-font-mono);
}
</style>
