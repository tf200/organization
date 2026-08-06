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
 *
 * All chrome is `.iz-steps`; this file has no <style> block.
 */
import { statusLabel, statusTone, stepName } from '../../lib/jobs'
import { formatTime } from '../../lib/format'
import type { JobStep } from '../../types'

defineProps<{ steps: JobStep[] }>()

/**
 * The step's own result carries a reason when it was skipped or degraded —
 * "Dry run mode - deck step skipped", "Deck ownership count is unavailable".
 * It was previously only reachable by opening a raw JSON dump.
 * @param step the step whose result to read
 */
function note(step: JobStep): string {
	const warning = step.result?.warning
	return typeof warning === 'string' ? warning : ''
}
</script>

<template>
	<ul class="iz-steps">
		<li v-for="step in steps" :key="step.id" class="iz-steps__step">
			<span class="iz-pill" :class="statusTone(step.status)">
				<span class="iz-dot" aria-hidden="true" />{{ statusLabel(step.status) }}
			</span>
			<span class="iz-steps__name">{{ stepName(step.stepKey) }}</span>
			<span v-if="step.attempt > 1" class="iz-badge iz-badge--warning">
				Attempt {{ step.attempt }}
			</span>
			<span v-if="step.finishedAt" class="iz-steps__time">
				{{ formatTime(step.finishedAt) }}
			</span>

			<p v-if="step.errorMessage" class="iz-steps__note iz-steps__note--error" role="alert">
				{{ step.errorMessage }}
			</p>
			<p v-else-if="note(step)" class="iz-steps__note">
				{{ note(step) }}
			</p>
		</li>
	</ul>
</template>
