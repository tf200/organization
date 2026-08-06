<script setup lang="ts">
/**
 * A job's execution plan: every step, in order, with its status.
 *
 * Shared by backup, rollback and handover jobs — the three services emit an
 * identical step record, and every step key they define has a name in
 * lib/jobs.ts. Backups never rendered theirs at all, so a running backup
 * showed an animation and nothing else.
 *
 * Steps are seeded `queued` when the job is created, so this list is the whole
 * plan from the first tick, not just what has run.
 */
import { statusLabel, statusTone, stepName } from '../../lib/jobs'
import { formatTime } from '../../lib/format'
import type { JobStep } from '../../types'

defineProps<{ steps: JobStep[] }>()

/**
 * The step's own result carries a reason when it was skipped or degraded —
 * "Dry run mode - deck step skipped", "Deck ownership count is unavailable".
 * It was previously only reachable by opening a raw JSON dump.
 * @param step
 */
function note(step: JobStep): string {
	const warning = step.result?.warning
	return typeof warning === 'string' ? warning : ''
}
</script>

<template>
	<ul class="job-steps">
		<li v-for="step in steps" :key="step.id" class="job-steps__step">
			<div class="job-steps__line">
				<span class="iz-pill" :class="statusTone(step.status)">
					<span class="iz-dot" aria-hidden="true" />{{ statusLabel(step.status) }}
				</span>
				<span class="job-steps__name">{{ stepName(step.stepKey) }}</span>
				<span v-if="step.attempt > 1" class="iz-badge iz-badge--warning">
					Attempt {{ step.attempt }}
				</span>
				<span v-if="step.finishedAt" class="iz-state job-steps__time">
					{{ formatTime(step.finishedAt) }}
				</span>
			</div>

			<p v-if="step.errorMessage" class="iz-error job-steps__note" role="alert">
				{{ step.errorMessage }}
			</p>
			<p v-else-if="note(step)" class="job-steps__note job-steps__note--quiet">
				{{ note(step) }}
			</p>
		</li>
	</ul>
</template>

<style scoped>
/* Layout only — pill, badge, error and state chrome all come from the theme. */
.job-steps {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.job-steps__step {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 0;
}

.job-steps__line {
	display: flex;
	align-items: center;
	gap: 10px;
	min-width: 0;
}

.job-steps__name {
	flex: 1;
	font-size: var(--iz-fs-md);
	min-width: 0;
	overflow-wrap: anywhere;
}

.job-steps__time {
	flex-shrink: 0;
	font-variant-numeric: tabular-nums;
}

.job-steps__note {
	margin: 0;
	/* Indented under the status pill so it reads as belonging to the step. */
	margin-left: 10px;
	font-size: var(--iz-fs-sm);
}

.job-steps__note--quiet {
	color: var(--iz-text-secondary);
}
</style>
