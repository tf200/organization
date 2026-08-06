<script setup lang="ts">
import { computed } from 'vue'
import type { Organization } from '../../../types'
import { formatDate, formatFileSize } from '../../../lib/format'

const props = defineProps<{
	org: Organization
	loading: boolean
}>()

defineEmits<{ edit: [] }>()

const contactFullName = computed(() => {
	const name = `${props.org.contactFirstName ?? ''} ${props.org.contactLastName ?? ''}`.trim()
	return name || 'Not set'
})

const planName = computed(() => props.org.subscription?.planName || 'Custom')

/* Same wording as the old KPI card: the date only, and 'Never' when unset. */
const expiresLabel = computed(() =>
	props.org.subscription?.endedAt ? formatDate(props.org.subscription.endedAt) : 'Never')
</script>

<template>
	<div class="overview">
		<div class="iz-metrics">
			<div class="iz-metric">
				<span class="iz-metric__value overview__plan">{{ planName }}</span>
				<span class="iz-metric__label">Current plan · expires {{ expiresLabel }}</span>
			</div>
			<div class="iz-metric">
				<span class="iz-metric__value">{{ org.usercount }} / {{ org.subscription?.maxMembers ?? 0 }}</span>
				<span class="iz-metric__label">Members</span>
			</div>
			<div class="iz-metric">
				<span class="iz-metric__value">{{ org.subscription?.maxProjects ?? 0 }}</span>
				<span class="iz-metric__label">Projects limit</span>
			</div>
		</div>

		<div class="overview__grid">
			<section class="iz-card overview__block">
				<span class="iz-section-title">Contact person</span>
				<dl class="iz-kv iz-kv--rows">
					<dt class="iz-kv__label">
						Name
					</dt>
					<dd class="iz-kv__value" :class="{ 'iz-kv__value--unset': contactFullName === 'Not set' }">
						{{ contactFullName }}
					</dd>

					<dt class="iz-kv__label">
						Email
					</dt>
					<dd class="iz-kv__value">
						<a v-if="org.contactEmail" :href="`mailto:${org.contactEmail}`">{{ org.contactEmail }}</a>
						<span v-else class="iz-kv__value--unset">Not set</span>
					</dd>

					<dt class="iz-kv__label">
						Phone
					</dt>
					<dd class="iz-kv__value">
						<a v-if="org.contactPhone" :href="`tel:${org.contactPhone}`">{{ org.contactPhone }}</a>
						<span v-else class="iz-kv__value--unset">Not set</span>
					</dd>
				</dl>
			</section>

			<section class="iz-card overview__block">
				<span class="iz-section-title">Organization settings</span>
				<dl class="iz-kv iz-kv--rows">
					<dt class="iz-kv__label">
						Organization ID
					</dt>
					<dd class="iz-kv__value iz-kv__value--mono">
						{{ org.id }}
					</dd>

					<dt class="iz-kv__label">
						Permissions
					</dt>
					<dd class="iz-kv__value overview__tags">
						<span class="iz-badge" :class="org.canAdd ? 'iz-badge--success' : 'iz-badge--danger'">
							{{ org.canAdd ? 'Add users' : 'Cannot add users' }}
						</span>
						<span class="iz-badge" :class="org.canRemove ? 'iz-badge--success' : 'iz-badge--danger'">
							{{ org.canRemove ? 'Remove users' : 'Cannot remove users' }}
						</span>
					</dd>
				</dl>
			</section>

			<section class="iz-card overview__block">
				<span class="iz-section-title">Storage quotas</span>
				<!-- The old UI rendered the literal string 'Loading...' in the value slot. -->
				<p v-if="loading && !org.plan" class="iz-state">
					Loading quotas…
				</p>
				<dl v-else class="iz-kv iz-kv--rows">
					<dt class="iz-kv__label">
						Shared / project
					</dt>
					<dd class="iz-kv__value">
						{{ formatFileSize(org.plan?.sharedStoragePerProject) }}
					</dd>

					<dt class="iz-kv__label">
						Private / user
					</dt>
					<dd class="iz-kv__value">
						{{ formatFileSize(org.plan?.privateStoragePerUser) }}
					</dd>
				</dl>
			</section>
		</div>

		<div class="overview__actions">
			<button class="iz-btn iz-btn--sm" type="button" @click="$emit('edit')">
				Edit organization
			</button>
		</div>
	</div>
</template>

<style scoped>
/* Layout only. */
.overview {
	display: flex;
	flex-direction: column;
	gap: var(--iz-gap);
}

.overview__plan {
	font-size: var(--iz-fs-lg);
}

.overview__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
	gap: var(--iz-gap);
}

/* Chrome from .iz-card; only the stacking direction is local. */
.overview__block {
	display: flex;
	flex-direction: column;
}

.overview__tags {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.overview__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}
</style>
