<script setup lang="ts">
import { computed, ref } from 'vue'
import ConfirmDialog from '../ConfirmDialog.vue'
import { ocs } from '../../lib/api'
import { formatFileSize } from '../../lib/format'
import type { Plan } from '../../types'

const props = defineProps<{
	plan: Plan
	expanded: boolean
}>()

const emit = defineEmits<{ toggle: []; deleted: []; edit: [] }>()

const inUse = computed(() => Number(props.plan.subscriptionCount ?? 0))

/* Price 0 renders as "Free", but the currency is always shown — it is always
   stored, and the old UI hid it behind `price ? ... : 'Free'`. */
const priceLabel = computed(() => (props.plan.price ? String(props.plan.price) : 'Free'))

const deleting = ref(false)
const deleteError = ref('')
const confirming = ref(false)

async function confirmDelete() {
	deleting.value = true
	deleteError.value = ''
	try {
		const { confirmPassword } = await import('../../lib/passwordConfirmation')
		await confirmPassword()
		await ocs(`plans/${props.plan.id}`, { method: 'DELETE' })
		confirming.value = false
		emit('deleted')
	} catch (e) {
		deleteError.value = e instanceof Error ? e.message : String(e)
	} finally {
		deleting.value = false
	}
}
</script>

<template>
	<article class="iz-row iz-row--card iz-row--expandable"
		:class="{ 'iz-row--expanded': expanded }">
		<div class="iz-row__header"
			role="button"
			tabindex="0"
			:aria-expanded="expanded"
			@click="emit('toggle')"
			@keydown.enter.prevent="emit('toggle')"
			@keydown.space.prevent="emit('toggle')">
			<span class="iz-identity__avatar iz-identity__avatar--soft" aria-hidden="true">
				{{ plan.name.charAt(0).toUpperCase() }}
			</span>

			<div class="plan-row__ident">
				<b class="plan-row__name">{{ plan.name }}</b>
				<span class="plan-row__meta">
					ID {{ plan.id }} · {{ plan.maxMembers }} members · {{ plan.maxProjects }} projects
				</span>
			</div>

			<div class="iz-row__actions">
				<span class="iz-badge iz-badge--muted">{{ plan.isPublic ? 'Public' : 'Private' }}</span>
				<span class="iz-pill iz-pill--muted">{{ priceLabel }}</span>
				<span v-if="inUse" class="iz-badge iz-badge--accent">
					{{ inUse }} subscription{{ inUse === 1 ? '' : 's' }}
				</span>
				<svg class="iz-row__chevron"
					:class="{ 'iz-row__chevron--open': expanded }"
					width="14"
					height="14"
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					stroke-width="2.5"
					stroke-linecap="round"
					stroke-linejoin="round"
					aria-hidden="true">
					<polyline points="6 9 12 15 18 9" />
				</svg>
			</div>
		</div>

		<div v-if="expanded" class="iz-row__detail">
			<div class="iz-metrics">
				<div class="iz-metric">
					<span class="iz-metric__value">{{ plan.maxMembers }}</span>
					<span class="iz-metric__label">Members limit</span>
				</div>
				<div class="iz-metric">
					<span class="iz-metric__value">{{ plan.maxProjects }}</span>
					<span class="iz-metric__label">Projects limit</span>
				</div>
				<div class="iz-metric">
					<span class="iz-metric__value">{{ priceLabel }}</span>
					<span class="iz-metric__label">{{ plan.currency }}</span>
				</div>
				<div class="iz-metric">
					<span class="iz-metric__value">{{ inUse }}</span>
					<span class="iz-metric__label">Subscriptions using</span>
				</div>
			</div>

			<div class="plan-row__grid">
				<section class="iz-card plan-row__block">
					<span class="iz-section-title">Storage quotas</span>
					<dl class="plan-row__kv">
						<dt>Shared / project</dt>
						<dd>{{ formatFileSize(plan.sharedStoragePerProject) }}</dd>
						<dt>Private / user</dt>
						<dd>{{ formatFileSize(plan.privateStoragePerUser) }}</dd>
					</dl>
				</section>
				<section class="iz-card plan-row__block">
					<span class="iz-section-title">Plan settings</span>
					<dl class="plan-row__kv">
						<dt>Plan ID</dt>
						<dd class="plan-row__mono">
							{{ plan.id }}
						</dd>
						<dt>Visibility</dt>
						<dd>{{ plan.isPublic ? 'Public' : 'Private' }}</dd>
						<dt>Currency</dt>
						<dd>{{ plan.currency }}</dd>
					</dl>
				</section>
			</div>

			<div class="plan-row__actions">
				<button class="iz-btn iz-btn--primary iz-btn--sm" type="button" @click="emit('edit')">
					Edit plan
				</button>
				<!-- Disabled when referenced: the server always refuses, and the old
				     UI swallowed "Cannot delete plan: it is used by N subscriptions"
				     into console.error, so the click appeared to do nothing. -->
				<button class="iz-btn iz-btn--danger-quiet iz-btn--sm"
					type="button"
					:disabled="inUse > 0"
					@click="confirming = true">
					Delete plan
				</button>
				<span v-if="inUse > 0" class="iz-state">
					In use by {{ inUse }} subscription{{ inUse === 1 ? '' : 's' }} — unassign before deleting.
				</span>
			</div>
		</div>

		<ConfirmDialog v-if="confirming"
			:title="`Delete ${plan.name}?`"
			message="This cannot be undone. Organizations already on this plan keep their limits until reassigned."
			confirm-label="Delete plan"
			busy-label="Deleting…"
			danger
			:busy="deleting"
			:error="deleteError"
			@confirm="confirmDelete"
			@cancel="confirming = false; deleteError = ''" />
	</article>
</template>

<style scoped>
/* Layout only. */
.plan-row__ident {
	display: flex;
	flex-direction: column;
	gap: 1px;
	min-width: 0;
	flex: 1;
}

.plan-row__name {
	font-size: var(--iz-fs-md);
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.plan-row__meta {
	font-size: var(--iz-fs-xs);
	color: var(--iz-text-muted);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.plan-row__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
	gap: var(--iz-gap);
	margin-top: var(--iz-gap);
}

/* Chrome from .iz-card; only the stacking direction is local. */
.plan-row__block {
	display: flex;
	flex-direction: column;
}

.plan-row__kv {
	display: grid;
	grid-template-columns: minmax(110px, auto) 1fr;
	gap: 6px 12px;
	margin: 0;
	font-size: var(--iz-fs-md);
}

.plan-row__kv dt {
	color: var(--iz-text-secondary);
}

.plan-row__kv dd {
	margin: 0;
}

.plan-row__mono {
	font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
	font-size: var(--iz-fs-sm);
}

.plan-row__actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin-top: var(--iz-gap);
}
</style>
