<script setup lang="ts">
import { computed } from 'vue'
import type { Organization } from '../../../types'
import { formatDateTime, formatFileSize, titleCase } from '../../../lib/format'

const props = defineProps<{ org: Organization }>()
defineEmits<{ changed: [] }>()

const sub = computed(() => props.org.subscription)

const STATUS_TONE: Record<string, string> = {
	active: 'iz-pill--success',
	expired: 'iz-pill--danger',
	cancelled: 'iz-pill--warning',
	paused: 'iz-pill--warning',
}

const statusTone = computed(() => STATUS_TONE[sub.value?.status] ?? 'iz-pill--muted')
const isExpired = computed(() => sub.value?.status === 'expired')
const isPaused = computed(() => sub.value?.status === 'paused')
const isTrial = computed(() => props.org.type === 'trial')
</script>

<template>
	<div class="subscription">
		<div v-if="isExpired" class="iz-error" role="status">
			This subscription ended on {{ formatDateTime(sub.endedAt) }}. The organization is over its term.
		</div>
		<div v-else-if="isPaused" class="iz-inset">
			This subscription is paused. Members cannot reach organization resources until it resumes.
		</div>

		<div class="subscription__grid">
			<section class="subscription__block">
				<span class="iz-section-title">Subscription</span>
				<dl class="subscription__kv">
					<dt>Subscription ID</dt>
					<dd class="subscription__mono">{{ sub?.id ?? '—' }}</dd>

					<dt>Status</dt>
					<dd><span class="iz-pill" :class="statusTone">{{ titleCase(sub?.status) }}</span></dd>

					<dt>Started</dt>
					<dd>{{ formatDateTime(sub?.startedAt) }}</dd>

					<dt>Ended</dt>
					<dd>{{ sub?.endedAt ? formatDateTime(sub.endedAt) : 'No end date' }}</dd>
				</dl>
			</section>

			<section class="subscription__block">
				<span class="iz-section-title">Plan · {{ sub?.planName || 'Custom' }}</span>
				<dl class="subscription__kv">
					<dt>Max members</dt>
					<dd>{{ sub?.maxMembers ?? '—' }}</dd>

					<dt>Max projects</dt>
					<dd>{{ sub?.maxProjects ?? '—' }}</dd>

					<dt>Shared / project</dt>
					<dd>{{ formatFileSize(org.plan?.sharedStoragePerProject) }}</dd>

					<dt>Private / user</dt>
					<dd>{{ formatFileSize(org.plan?.privateStoragePerUser) }}</dd>
				</dl>
			</section>
		</div>

		<div v-if="isTrial" class="subscription__actions">
			<button class="iz-btn iz-btn--primary iz-btn--sm" type="button" @click="$emit('changed')">
				Convert to standard
			</button>
			<span class="iz-state">Converting assigns a standard plan and starts a new term.</span>
		</div>
	</div>
</template>

<style scoped>
/* Layout only. */
.subscription {
	display: flex;
	flex-direction: column;
	gap: var(--iz-gap);
}

.subscription__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: var(--iz-gap);
}

.subscription__block {
	display: flex;
	flex-direction: column;
	padding: var(--iz-pad-card);
	border: 1px solid var(--iz-border);
	border-radius: var(--iz-radius-lg);
}

.subscription__kv {
	display: grid;
	grid-template-columns: minmax(110px, auto) 1fr;
	gap: 6px 12px;
	margin: 0;
	font-size: var(--iz-fs-md);
}

.subscription__kv dt {
	color: var(--iz-text-secondary);
}

.subscription__kv dd {
	margin: 0;
	min-width: 0;
	overflow-wrap: anywhere;
}

.subscription__mono {
	font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
	font-size: var(--iz-fs-sm);
}

.subscription__actions {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}
</style>
