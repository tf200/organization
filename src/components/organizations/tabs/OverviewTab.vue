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
			<section class="overview__block">
				<span class="iz-section-title">Contact person</span>
				<dl class="overview__kv">
					<dt>Name</dt>
					<dd :class="{ 'overview__unset': contactFullName === 'Not set' }">{{ contactFullName }}</dd>

					<dt>Email</dt>
					<dd>
						<a v-if="org.contactEmail" :href="`mailto:${org.contactEmail}`">{{ org.contactEmail }}</a>
						<span v-else class="overview__unset">Not set</span>
					</dd>

					<dt>Phone</dt>
					<dd>
						<a v-if="org.contactPhone" :href="`tel:${org.contactPhone}`">{{ org.contactPhone }}</a>
						<span v-else class="overview__unset">Not set</span>
					</dd>
				</dl>
			</section>

			<section class="overview__block">
				<span class="iz-section-title">Organization settings</span>
				<dl class="overview__kv">
					<dt>Organization ID</dt>
					<dd class="overview__mono">{{ org.id }}</dd>

					<dt>Permissions</dt>
					<dd class="overview__tags">
						<span class="iz-badge" :class="org.canAdd ? 'iz-badge--success' : 'iz-badge--danger'">
							{{ org.canAdd ? 'Add users' : 'Cannot add users' }}
						</span>
						<span class="iz-badge" :class="org.canRemove ? 'iz-badge--success' : 'iz-badge--danger'">
							{{ org.canRemove ? 'Remove users' : 'Cannot remove users' }}
						</span>
					</dd>
				</dl>
			</section>

			<section class="overview__block">
				<span class="iz-section-title">Storage quotas</span>
				<!-- The old UI rendered the literal string 'Loading...' in the value slot. -->
				<p v-if="loading && !org.plan" class="iz-state">Loading quotas…</p>
				<dl v-else class="overview__kv">
					<dt>Shared / project</dt>
					<dd>{{ formatFileSize(org.plan?.sharedStoragePerProject) }}</dd>

					<dt>Private / user</dt>
					<dd>{{ formatFileSize(org.plan?.privateStoragePerUser) }}</dd>
				</dl>
			</section>
		</div>

		<div class="overview__actions">
			<button class="iz-btn iz-btn--sm" type="button" @click="$emit('edit')">Edit organization</button>
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

.overview__block {
	display: flex;
	flex-direction: column;
	padding: var(--iz-pad-card);
	border: 1px solid var(--iz-border);
	border-radius: var(--iz-radius-lg);
}

.overview__kv {
	display: grid;
	grid-template-columns: minmax(90px, auto) 1fr;
	gap: 6px 12px;
	margin: 0;
	font-size: var(--iz-fs-md);
}

.overview__kv dt {
	color: var(--iz-text-secondary);
}

.overview__kv dd {
	margin: 0;
	min-width: 0;
	overflow-wrap: anywhere;
}

.overview__unset {
	color: var(--iz-text-muted);
	font-style: italic;
}

.overview__mono {
	font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
	font-size: var(--iz-fs-sm);
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
