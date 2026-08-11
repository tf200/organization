<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ocs } from '../../lib/api'
import { useAsync } from '../../composables/useAsync'
import { formatDate } from '../../lib/format'
import type { TrialSettings } from '../../types'

const GIB = 1073741824
const MIB = 1048576

type Unit = 'MB' | 'GB'

const form = ref({
	planName: '',
	/**
	 * Kept as a STRING, deliberately.
	 *
	 * The old SettingsView loaded this with parseInt() into a number field and
	 * saved it back as `${n} days`, so a configured "1 month" silently became
	 * "1 days" — from merely opening and saving, without touching the field.
	 * The config accepts anything DateInterval::createFromDateString() parses,
	 * so the input must too.
	 */
	duration: '',
	maxMembers: 3,
	maxProjects: 1,
	sharedValue: 100,
	sharedUnit: 'MB' as Unit,
	privateValue: 100,
	privateUnit: 'MB' as Unit,
})

const saved = ref(false)
const neverConfigured = ref(false)

function splitBytes(bytes: number): { value: number; unit: Unit } {
	if (bytes > 0 && bytes % GIB === 0) return { value: bytes / GIB, unit: 'GB' }
	if (bytes > 0) return { value: bytes / MIB, unit: 'MB' }
	return { value: 0, unit: 'MB' }
}

function toBytes(value: number, unit: Unit): number {
	return Math.round(value * (unit === 'GB' ? GIB : MIB))
}

const sharedBytes = computed(() => toBytes(form.value.sharedValue, form.value.sharedUnit))
const privateBytes = computed(() => toBytes(form.value.privateValue, form.value.privateUnit))

const load = useAsync(async () => {
	const data = await ocs<TrialSettings>('admin/settings/trial')
	const shared = splitBytes(Number(data?.sharedStoragePerProject ?? 0))
	const priv = splitBytes(Number(data?.privateStoragePerUser ?? 0))
	form.value = {
		planName: data?.planName ?? 'Trial Plan',
		duration: data?.duration ?? '7 days',
		maxMembers: Number(data?.maxMembers ?? 3),
		maxProjects: Number(data?.maxProjects ?? 1),
		sharedValue: shared.value,
		sharedUnit: shared.unit,
		privateValue: priv.value,
		privateUnit: priv.unit,
	}
	return data
})

onMounted(() => load.run())

/**
 * Advisory only. Mirrors the common DateInterval shapes so the admin can see
 * what a string means, but never rejects one the server would accept.
 */
const resolvedEnd = computed(() => {
	const m = /^\s*(\d+)\s*(day|week|month|year)s?\s*$/i.exec(form.value.duration)
	if (!m) return null
	const n = Number(m[1])
	const unit = m[2].toLowerCase()
	const d = new Date()
	if (unit === 'day') d.setDate(d.getDate() + n)
	else if (unit === 'week') d.setDate(d.getDate() + n * 7)
	else if (unit === 'month') d.setMonth(d.getMonth() + n)
	else d.setFullYear(d.getFullYear() + n)
	return d.toISOString()
})

const save = useAsync(async () => {
	const { confirmPassword } = await import('../../lib/passwordConfirmation')
	await confirmPassword()
	// All six always sent: saveTrialSettings() defaults anything omitted,
	// so a partial PUT silently resets the rest.
	await ocs('admin/settings/trial', {
		method: 'PUT',
		body: {
			trial_plan_name: form.value.planName,
			trial_duration: form.value.duration,
			trial_max_members: form.value.maxMembers,
			trial_max_projects: form.value.maxProjects,
			trial_shared_storage_gb: sharedBytes.value / GIB,
			trial_private_storage_gb: privateBytes.value / GIB,
		},
	})
	saved.value = true
	neverConfigured.value = false
	await load.run()
})

function onSubmit() {
	saved.value = false
	save.run()
}
</script>

<template>
	<div class="trial">
		<section class="iz-panel">
			<div class="iz-panel__header">
				<h3 class="iz-panel__title">
					Trial defaults
				</h3>
			</div>

			<p class="trial__lede">
				Applied to every trial organization at the moment it is provisioned.
				Changing them does not affect trials that already exist.
			</p>

			<div v-if="load.pending.value" class="trial__state">
				<span class="iz-spinner iz-spinner--lg" aria-label="Loading trial defaults" />
			</div>

			<div v-else-if="load.error.value" class="iz-error" role="alert">
				{{ load.error.value }}
				<button class="iz-btn iz-btn--accent iz-btn--sm" type="button" @click="load.run()">
					Try again
				</button>
			</div>

			<form v-else class="trial__form" @submit.prevent="onSubmit">
				<div class="trial__grid">
					<div>
						<label class="iz-label" for="trial-plan-name">Trial plan name</label>
						<input id="trial-plan-name"
							v-model="form.planName"
							class="iz-input"
							required>
						<p class="iz-state">
							Each trial gets its own plan named “{{ form.planName || 'Trial Plan' }} — &lt;organization&gt;”.
						</p>
					</div>

					<div>
						<label class="iz-label" for="trial-duration">Trial duration</label>
						<input id="trial-duration"
							v-model="form.duration"
							class="iz-input"
							required
							placeholder="e.g. 7 days">
						<p v-if="resolvedEnd" class="iz-state">
							Ends <strong class="trial__resolved">{{ formatDate(resolvedEnd) }}</strong> for a trial started today.
						</p>
						<p v-else class="iz-state">
							Any interval PHP can parse — “7 days”, “2 weeks”, “1 month”. The server validates it.
						</p>
					</div>

					<div>
						<label class="iz-label" for="trial-max-members">Max members</label>
						<input id="trial-max-members"
							v-model.number="form.maxMembers"
							class="iz-input"
							type="number"
							min="1"
							required>
					</div>

					<div>
						<label class="iz-label" for="trial-max-projects">Max projects</label>
						<input id="trial-max-projects"
							v-model.number="form.maxProjects"
							class="iz-input"
							type="number"
							min="1"
							required>
					</div>

					<div>
						<label class="iz-label" for="trial-shared">Shared storage per project</label>
						<div class="trial__pair">
							<input id="trial-shared"
								v-model.number="form.sharedValue"
								class="iz-input trial__num"
								type="number"
								min="0.001"
								step="0.001"
								required>
							<select v-model="form.sharedUnit" class="iz-select trial__unit" aria-label="Shared storage unit">
								<option value="MB">
									MB
								</option>
								<option value="GB">
									GB
								</option>
							</select>
						</div>
						<!-- Exact byte count shown because the old view rounded GB to 3dp
						     and re-saved 105,226,467 in place of 104,857,600. -->
						<p class="iz-state">
							{{ sharedBytes.toLocaleString() }} bytes
						</p>
					</div>

					<div>
						<label class="iz-label" for="trial-private">Private storage per user</label>
						<div class="trial__pair">
							<input id="trial-private"
								v-model.number="form.privateValue"
								class="iz-input trial__num"
								type="number"
								min="0.001"
								step="0.001"
								required>
							<select v-model="form.privateUnit" class="iz-select trial__unit" aria-label="Private storage unit">
								<option value="MB">
									MB
								</option>
								<option value="GB">
									GB
								</option>
							</select>
						</div>
						<p class="iz-state">
							{{ privateBytes > 0 ? privateBytes.toLocaleString() + ' bytes' : 'No private storage allocated' }}
						</p>
					</div>
				</div>

				<div class="iz-inset trial__preview">
					<span class="trial__preview-label">A new trial will get</span>
					<div class="trial__preview-body">
						<span class="iz-badge iz-badge--cat-5">Trial</span>
						<span>
							{{ form.duration || '—' }} ·
							{{ form.maxMembers }} member{{ form.maxMembers === 1 ? '' : 's' }} ·
							{{ form.maxProjects }} project{{ form.maxProjects === 1 ? '' : 's' }} ·
							{{ form.sharedValue }} {{ form.sharedUnit }} shared per project
						</span>
					</div>
				</div>

				<div v-if="save.error.value" class="iz-error" role="alert">
					{{ save.error.value }}
				</div>
				<div v-else-if="saved" class="trial__saved" role="status">
					Trial defaults saved.
				</div>

				<div class="trial__actions">
					<button class="iz-btn iz-btn--primary iz-btn--sm" type="submit" :disabled="save.pending.value">
						<span v-if="save.pending.value" class="iz-spinner" />
						{{ save.pending.value ? 'Saving…' : 'Save trial defaults' }}
					</button>
					<span class="iz-state">Requires password confirmation.</span>
				</div>
			</form>
		</section>
	</div>
</template>

<style scoped>
/* Layout only. */
.trial__lede {
	margin: 0 0 var(--iz-gap);
	font-size: var(--iz-fs-md);
	color: var(--iz-text-secondary);
}

.trial__form {
	display: flex;
	flex-direction: column;
	gap: var(--iz-gap);
}

.trial__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
	gap: var(--iz-gap);
}

.trial__pair {
	display: flex;
	gap: 8px;
	align-items: center;
}

/* The number carries the value, so it gets the room; the unit only ever says
   MB or GB. Both qualified on .trial__pair to beat the theme's width:100% —
   unqualified they tie at (0,2,0) and lose, which collapsed the number input
   to 26px while the select stretched to 216px. */
.trial__pair .trial__num {
	flex: 1 1 auto;
	min-width: 0;
	width: auto;
}

.trial__pair .trial__unit {
	flex: 0 0 5.5rem;
	width: 5.5rem;
}

.trial__resolved {
	font-style: normal;
	color: var(--iz-text);
}

.trial__preview-label {
	display: block;
	margin-bottom: 6px;
	font-size: var(--iz-fs-micro);
	font-weight: 700;
	letter-spacing: 0.5px;
	text-transform: uppercase;
	color: var(--iz-text-secondary);
}

.trial__preview-body {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	font-size: var(--iz-fs-md);
	color: var(--iz-text);
}

.trial__saved {
	font-size: var(--iz-fs-md);
	color: var(--iz-success-text);
}

.trial__actions {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.trial__state {
	display: flex;
	justify-content: center;
	padding: 32px 0;
}
</style>
