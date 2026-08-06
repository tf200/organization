<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import ConfirmDialog from '../../ConfirmDialog.vue'
import { ocs } from '../../../lib/api'
import type { Member, Organization } from '../../../types'

const props = defineProps<{
	org: Organization
	members: Member[]
}>()

const emit = defineEmits<{ 'members-updated': [Member[]] }>()

type Mode = 'current' | 'add' | 'create'
const mode = ref<Mode>('current')

/* Seat maths, preserved exactly from ManageMembersModal. */
const maxMembers = computed(() => Number(props.org.subscription?.maxMembers || 0))
const seatsLeft = computed(() => Math.max(maxMembers.value - props.members.length, 0))
const seatsTone = computed(() => (seatsLeft.value <= 3 ? 'iz-pill--warning' : 'iz-pill--success'))

/* ── remove ─────────────────────────────────────────────── */
const removeTarget = ref<Member | null>(null)
const removeBusy = ref(false)
const removeError = ref('')

async function confirmRemove() {
	if (!removeTarget.value) return
	removeBusy.value = true
	removeError.value = ''
	try {
		const data = await ocs<{ members?: Member[] }>(
			`organizations/${props.org.id}/members/${encodeURIComponent(removeTarget.value.uid)}`,
			{ method: 'DELETE' },
		)
		emit('members-updated', data?.members ?? [])
		removeTarget.value = null
	} catch (e) {
		removeError.value = e instanceof Error ? e.message : String(e)
	} finally {
		removeBusy.value = false
	}
}

/* ── add existing ───────────────────────────────────────── */
const query = ref('')
const results = ref<Member[]>([])
const searching = ref(false)
const searchError = ref('')
const addingUid = ref<string | null>(null)
let searchTimer: ReturnType<typeof setTimeout> | undefined

function onQuery() {
	if (searchTimer) clearTimeout(searchTimer)
	if (!query.value.trim()) {
		results.value = []
		return
	}
	searchTimer = setTimeout(runSearch, 300) // same 300ms debounce as before
}

onBeforeUnmount(() => { if (searchTimer) clearTimeout(searchTimer) })

async function runSearch() {
	searching.value = true
	searchError.value = ''
	try {
		const data = await ocs<{ users?: Member[] }>(
			`organizations/${props.org.id}/available-users`,
			{ params: { search: query.value.trim() } },
		)
		const existing = new Set(props.members.map((m) => m.uid))
		results.value = (data?.users ?? []).filter((u) => !existing.has(u.uid))
	} catch (e) {
		searchError.value = e instanceof Error ? e.message : String(e)
		results.value = []
	} finally {
		searching.value = false
	}
}

async function addMember(user: Member) {
	addingUid.value = user.uid
	searchError.value = ''
	try {
		const data = await ocs<{ members?: Member[] }>(
			`organizations/${props.org.id}/members`,
			{ method: 'POST', body: { userId: user.uid } },
		)
		emit('members-updated', data?.members ?? [])
		query.value = ''
		results.value = []
		mode.value = 'current'
	} catch (e) {
		searchError.value = e instanceof Error ? e.message : String(e)
	} finally {
		addingUid.value = null
	}
}

/* ── create account ─────────────────────────────────────── */
const draft = ref({ userId: '', password: '', displayName: '', email: '' })
const creating = ref(false)
const createError = ref('')

const canCreate = computed(() =>
	draft.value.userId.trim() !== '' && draft.value.password.trim() !== '')

async function createAccount() {
	if (!canCreate.value) return
	creating.value = true
	createError.value = ''
	try {
		const data = await ocs<{ members?: Member[] }>(
			`organizations/${props.org.id}/users`,
			{
				method: 'POST',
				body: {
					userId: draft.value.userId.trim(),
					password: draft.value.password,
					displayName: draft.value.displayName.trim() || null,
					email: draft.value.email.trim() || null,
				},
			},
		)
		emit('members-updated', data?.members ?? [])
		draft.value = { userId: '', password: '', displayName: '', email: '' }
		mode.value = 'current'
	} catch (e) {
		createError.value = e instanceof Error ? e.message : String(e)
	} finally {
		creating.value = false
	}
}
</script>

<template>
	<div class="members">
		<div class="members__toolbar">
			<div class="iz-segment" role="tablist" aria-label="Member actions">
				<button
					class="iz-btn iz-btn--sm"
					:class="{ 'iz-btn--active': mode === 'current' }"
					type="button"
					@click="mode = 'current'">Current</button>
				<button
					v-if="seatsLeft > 0"
					class="iz-btn iz-btn--sm"
					:class="{ 'iz-btn--active': mode === 'add' }"
					type="button"
					@click="mode = 'add'">Add existing</button>
				<button
					v-if="seatsLeft > 0"
					class="iz-btn iz-btn--sm"
					:class="{ 'iz-btn--active': mode === 'create' }"
					type="button"
					@click="mode = 'create'">Create account</button>
			</div>

			<span class="iz-pill" :class="seatsTone">{{ seatsLeft }} seats available</span>
			<span class="members__count">{{ members.length }} of {{ maxMembers }} members</span>
		</div>

		<div v-if="seatsLeft === 0" class="iz-inset">
			Member limit reached. Upgrade the subscription to add more members.
		</div>

		<!-- Add existing -->
		<div v-if="mode === 'add'" class="iz-user-picker">
			<input
				v-model="query"
				class="iz-input"
				type="search"
				placeholder="Search users by name or email…"
				aria-label="Search users"
				@input="onQuery">

			<p v-if="searching" class="iz-state">Searching…</p>
			<div v-if="searchError" class="iz-error" role="alert">{{ searchError }}</div>

			<ul v-if="results.length" class="iz-user-picker__results">
				<li v-for="user in results" :key="user.uid" class="iz-user-picker__result">
					<span class="iz-identity__avatar iz-identity__avatar--sm iz-identity__avatar--soft" aria-hidden="true">
						{{ (user.displayName || user.uid).charAt(0).toUpperCase() }}
					</span>
					<div class="iz-identity__body">
						<span class="iz-identity__name">{{ user.displayName || user.uid }}</span>
						<span class="iz-identity__meta members__mono">{{ user.uid }}</span>
					</div>
					<button
						class="iz-user-picker__add"
						type="button"
						:disabled="addingUid === user.uid"
						:aria-label="`Add ${user.displayName || user.uid}`"
						@click="addMember(user)">
						<span v-if="addingUid === user.uid" class="iz-spinner"></span>
						<template v-else>+</template>
					</button>
				</li>
			</ul>

			<p v-else-if="query.trim() && !searching" class="iz-state">
				No users found matching “{{ query.trim() }}”.
			</p>
		</div>

		<!-- Create account -->
		<form v-else-if="mode === 'create'" class="members__form" @submit.prevent="createAccount">
			<div class="members__form-grid">
				<div>
					<label class="iz-label" for="new-uid">User ID <span aria-hidden="true">*</span></label>
					<input id="new-uid" v-model="draft.userId" class="iz-input" required placeholder="username">
				</div>
				<div>
					<label class="iz-label" for="new-pw">Password <span aria-hidden="true">*</span></label>
					<input id="new-pw" v-model="draft.password" class="iz-input" type="password" required placeholder="Temporary password">
				</div>
				<div>
					<label class="iz-label" for="new-name">Display name</label>
					<input id="new-name" v-model="draft.displayName" class="iz-input" placeholder="Full name (optional)">
				</div>
				<div>
					<label class="iz-label" for="new-email">Email</label>
					<input id="new-email" v-model="draft.email" class="iz-input" type="email" placeholder="email@example.com (optional)">
				</div>
			</div>

			<div v-if="createError" class="iz-error" role="alert">{{ createError }}</div>

			<button class="iz-btn iz-btn--primary iz-btn--sm" type="submit" :disabled="!canCreate || creating">
				<span v-if="creating" class="iz-spinner"></span>
				{{ creating ? 'Creating…' : 'Create account & add' }}
			</button>
		</form>

		<!-- Current -->
		<div v-else>
			<div v-if="!members.length" class="iz-empty">No members yet.</div>
			<ul v-else class="members__list">
				<li v-for="member in members" :key="member.uid" class="members__row">
					<span class="iz-identity__avatar iz-identity__avatar--sm" aria-hidden="true">
						{{ (member.displayName || member.uid).charAt(0).toUpperCase() }}
					</span>
					<div class="iz-identity__body">
						<span class="iz-identity__name">{{ member.displayName || member.uid }}</span>
						<span class="iz-identity__meta members__mono">
							{{ member.uid }}<template v-if="member.email"> · {{ member.email }}</template>
						</span>
					</div>
					<span class="iz-badge" :class="member.role === 'admin' ? 'iz-badge--success' : 'iz-badge--muted'">
						{{ member.role === 'admin' ? 'Admin' : 'Member' }}
					</span>
					<!-- Disabled with a reason rather than hidden: absence reads as a
					     missing feature, not as a rule. -->
					<button
						class="iz-btn iz-btn--icon iz-btn--sm"
						type="button"
						:disabled="member.role === 'admin'"
						:title="member.role === 'admin'
							? 'Organization admins cannot be removed'
							: `Remove ${member.displayName || member.uid}`"
						:aria-label="`Remove ${member.displayName || member.uid}`"
						@click="removeTarget = member">&times;</button>
				</li>
			</ul>
		</div>

		<ConfirmDialog
			v-if="removeTarget"
			:title="`Remove ${removeTarget.displayName || removeTarget.uid}?`"
			:message="`They will lose access to this organization's projects and shared files. Their Nextcloud account is not deleted.`"
			confirm-label="Remove member"
			busy-label="Removing…"
			danger
			:busy="removeBusy"
			:error="removeError"
			@confirm="confirmRemove"
			@cancel="removeTarget = null; removeError = ''" />
	</div>
</template>

<style scoped>
/* Layout only. */
.members {
	display: flex;
	flex-direction: column;
	gap: var(--iz-gap);
}

.members__toolbar {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.members__count {
	font-size: var(--iz-fs-sm);
	color: var(--iz-text-secondary);
	margin-left: auto;
}

.members__mono {
	font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.members__list {
	list-style: none;
	margin: 0;
	padding: 0;
	border: 1px solid var(--iz-border);
	border-radius: var(--iz-radius-lg);
	overflow: hidden;
}

.members__row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 12px;
	border-bottom: 1px solid var(--iz-border);
}

.members__row:last-child {
	border-bottom: 0;
}

.members__form {
	display: flex;
	flex-direction: column;
	gap: var(--iz-gap);
}

.members__form-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 14px;
}
</style>
