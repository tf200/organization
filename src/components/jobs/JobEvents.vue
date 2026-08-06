<script setup lang="ts">
/**
 * A job's activity log.
 *
 * One component for all three job kinds, which is what the redesign plan
 * called for — backup events, rollback events and handover events are the
 * same record and were about to be rendered three different ways.
 *
 * Laid out as a three-column grid with the rows emitted flat rather than
 * wrapped per entry, so timestamps stay column-aligned down the list. That
 * alignment is the whole reason to prefer a grid over a stack of flex rows.
 */
import { formatTime } from '../../lib/format'
import { eventTone } from '../../lib/jobs'
import type { JobEvent } from '../../types'

withDefaults(defineProps<{
	events: JobEvent[]
	loading?: boolean
	/** Handover events name the step they belong to; backup and rollback do not. */
	showStep?: boolean
}>(), { loading: false, showStep: false })
</script>

<template>
	<div class="job-events">
		<p v-if="loading && !events.length" class="iz-state">
			Loading activity…
		</p>
		<p v-else-if="!events.length" class="iz-state">
			No activity recorded yet.
		</p>
		<div v-else class="job-events__grid">
			<template v-for="event in events" :key="event.id">
				<span class="iz-badge" :class="eventTone(event.level)">{{ event.level }}</span>
				<span class="iz-state job-events__time">{{ formatTime(event.createdAt) }}</span>
				<span class="job-events__message">
					{{ event.message }}
					<span v-if="showStep && event.stepKey" class="iz-badge iz-badge--muted">{{ event.stepKey }}</span>
				</span>
			</template>
		</div>
	</div>
</template>

<style scoped>
/* Layout only. The scroll cap is layout too: an activity log grows without
   bound and would otherwise push the rest of the detail off the screen. */
.job-events__grid {
	display: grid;
	grid-template-columns: auto auto 1fr;
	align-items: baseline;
	gap: 6px 12px;
	max-height: 280px;
	overflow-y: auto;
}

.job-events__time {
	white-space: nowrap;
	font-variant-numeric: tabular-nums;
}

.job-events__message {
	font-size: var(--iz-fs-md);
	color: var(--iz-text-secondary);
	min-width: 0;
	overflow-wrap: anywhere;
}
</style>
