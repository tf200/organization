<script setup lang="ts">
/**
 * A job's activity log.
 *
 * One component for all three job kinds, which is what the redesign plan
 * called for — backup events, rollback events and handover events are the
 * same record and were about to be rendered three different ways.
 *
 * The rows are emitted flat rather than wrapped per entry, because that is
 * what keeps the timestamps column-aligned in `.iz-log`'s grid. All chrome,
 * including the height cap, comes from the primitive.
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
	<p v-if="loading && !events.length" class="iz-state">
		Loading activity…
	</p>
	<p v-else-if="!events.length" class="iz-state">
		No activity recorded yet.
	</p>
	<div v-else class="iz-log">
		<template v-for="event in events" :key="event.id">
			<span class="iz-badge" :class="eventTone(event.level)">{{ event.level }}</span>
			<span class="iz-log__time">{{ formatTime(event.createdAt) }}</span>
			<span class="iz-log__message">
				{{ event.message }}
				<span v-if="showStep && event.stepKey" class="iz-badge iz-badge--muted">{{ event.stepKey }}</span>
			</span>
		</template>
	</div>
</template>
