<script setup lang="ts">
import { computed } from 'vue'
import type { Organization } from '../../types'
import { formatDate, titleCase } from '../../lib/format'

const props = defineProps<{
	org: Organization
	expanded: boolean
}>()

const emit = defineEmits<{ toggle: [] }>()

/* Never build a class name from data — 'iz-pill--' + status silently emits a
   class that may not exist. Explicit table, neutral fallback. */
const STATUS_TONE: Record<string, string> = {
	active: 'iz-pill--success',
	expired: 'iz-pill--danger',
	cancelled: 'iz-pill--warning',
	paused: 'iz-pill--warning',
}

const statusTone = computed(() => STATUS_TONE[props.org.subscription?.status] ?? 'iz-pill--muted')
const statusLabel = computed(() => titleCase(props.org.subscription?.status))

const isTrial = computed(() => props.org.type === 'trial')

const maxMembers = computed(() => Number(props.org.subscription?.maxMembers) || 0)

/* Guarded: the existing OrgDetails computes NaN% when maxMembers is 0. */
const seatPct = computed(() => {
	if (maxMembers.value <= 0) return 0
	return Math.min((props.org.usercount / maxMembers.value) * 100, 100)
})

const seatTone = computed(() => {
	if (maxMembers.value <= 0) return 'iz-meter__fill--neutral'
	if (seatPct.value >= 100) return 'iz-meter__fill--danger'
	if (seatPct.value >= 80) return 'iz-meter__fill--warn'
	return 'iz-meter__fill--ok'
})

const endLabel = computed(() => {
	const ended = props.org.subscription?.endedAt
	return ended ? formatDate(ended) : 'No end date'
})
</script>

<template>
	<article
		class="iz-row iz-row--card iz-row--expandable"
		:class="{ 'iz-row--expanded': expanded }">
		<div
			class="iz-row__header"
			role="button"
			tabindex="0"
			:aria-expanded="expanded"
			@click="emit('toggle')"
			@keydown.enter.prevent="emit('toggle')"
			@keydown.space.prevent="emit('toggle')">
			<span
				class="iz-identity__avatar"
				:class="{ 'iz-identity__avatar--soft': isTrial }"
				aria-hidden="true">{{ org.displayname.charAt(0).toUpperCase() }}</span>

			<div class="org-row__ident">
				<b class="org-row__name">{{ org.displayname }}</b>
				<span class="org-row__meta">
					ID {{ org.id }} · {{ org.subscription?.planName || 'Custom' }} · {{ org.adminUid }}
				</span>
			</div>

			<div class="org-row__seats">
				<span class="org-row__seat-count">{{ org.usercount }} / {{ maxMembers }} members</span>
				<div class="iz-meter">
					<div class="iz-meter__fill" :class="seatTone" :style="{ width: seatPct + '%' }"></div>
				</div>
			</div>

			<div class="iz-row__actions">
				<span class="iz-badge" :class="isTrial ? 'iz-badge--cat-5' : 'iz-badge--muted'">
					{{ isTrial ? 'Trial' : 'Standard' }}
				</span>
				<span class="iz-pill" :class="statusTone">
					<span class="iz-dot" aria-hidden="true"></span>{{ statusLabel }}
				</span>
				<span class="org-row__end">{{ endLabel }}</span>
				<svg
					class="iz-row__chevron"
					:class="{ 'iz-row__chevron--open': expanded }"
					width="14" height="14" viewBox="0 0 24 24" fill="none"
					stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
					stroke-linejoin="round" aria-hidden="true">
					<polyline points="6 9 12 15 18 9" />
				</svg>
			</div>
		</div>

		<div v-if="expanded" class="iz-row__detail">
			<slot />
		</div>
	</article>
</template>

<style scoped>
/* Layout only. Surface, border, radius, hover and the chevron rotation all
   come from .iz-row--card / --expandable / __chevron. */
.org-row__ident {
	display: flex;
	flex-direction: column;
	gap: 1px;
	min-width: 0;
	flex: 1;
}

.org-row__name {
	font-size: var(--iz-fs-md);
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.org-row__meta {
	font-size: var(--iz-fs-xs);
	color: var(--iz-text-muted);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.org-row__seats {
	display: flex;
	flex-direction: column;
	gap: 4px;
	width: 110px;
	flex-shrink: 0;
}

.org-row__seat-count {
	font-size: var(--iz-fs-xs);
	color: var(--iz-text-secondary);
	font-variant-numeric: tabular-nums;
}

.org-row__end {
	font-size: var(--iz-fs-xs);
	color: var(--iz-text-muted);
	white-space: nowrap;
}

@media (max-width: 700px) {
	.org-row__seats { display: none; }
	.org-row__end { display: none; }
}
</style>
