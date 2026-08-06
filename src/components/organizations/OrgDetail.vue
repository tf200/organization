<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import OverviewTab from './tabs/OverviewTab.vue'
import MembersTab from './tabs/MembersTab.vue'
import SubscriptionTab from './tabs/SubscriptionTab.vue'
import BackupsTab from './tabs/BackupsTab.vue'
import HandoverTab from './tabs/HandoverTab.vue'
import { ocs } from '../../lib/api'
import { useAsync } from '../../composables/useAsync'
import type { Member, Organization } from '../../types'

const props = defineProps<{ org: Organization }>()
const emit = defineEmits<{
	patch: [Partial<Organization>]
	changed: []
}>()

type DetailTab = 'overview' | 'members' | 'subscription' | 'backups' | 'handover'
const activeTab = ref<DetailTab>('overview')

/** The full record, fetched on first expand. Falls back to the list row. */
const full = ref<Organization>(props.org)
const members = ref<Member[]>(props.org.members ?? [])

const detail = useAsync(async () => {
	const data = await ocs<{
		organization: Partial<Organization>
		subscription?: Organization['subscription']
		plan?: Organization['plan']
		members?: Member[]
	}>(`organizations/${props.org.id}`)

	members.value = data?.members ?? []
	full.value = {
		...props.org,
		...(data?.organization ?? {}),
		// usercount comes from the list endpoint; the detail one omits it.
		usercount: members.value.length || props.org.usercount,
		subscription: { ...props.org.subscription, ...(data?.subscription ?? {}) },
		plan: data?.plan,
		members: members.value,
	}
	emit('patch', { usercount: full.value.usercount, plan: full.value.plan })
	return full.value
})

onMounted(() => detail.run())

function onMembersUpdated(next: Member[]) {
	members.value = next
	full.value = { ...full.value, members: next, usercount: next.length }
	emit('patch', { usercount: next.length })
}

const tabs = computed(() => [
	{ key: 'overview' as const, label: 'Overview', count: undefined as number | undefined },
	{ key: 'members' as const, label: 'Members', count: members.value.length },
	{ key: 'subscription' as const, label: 'Subscription', count: undefined },
	{ key: 'backups' as const, label: 'Backups', count: undefined },
	{ key: 'handover' as const, label: 'Handover', count: undefined },
])
</script>

<template>
	<div class="org-detail">
		<nav class="iz-tabs" role="tablist">
			<button v-for="tab in tabs"
				:key="tab.key"
				class="iz-tab"
				:class="{ 'iz-tab--active': activeTab === tab.key }"
				type="button"
				role="tab"
				:aria-selected="activeTab === tab.key"
				@click="activeTab = tab.key">
				{{ tab.label }}
				<span v-if="tab.count !== undefined" class="iz-tab__count">{{ tab.count }}</span>
			</button>
		</nav>

		<div v-if="detail.error.value" class="iz-error org-detail__error" role="alert">
			{{ detail.error.value }}
			<button class="iz-btn iz-btn--accent iz-btn--sm" type="button" @click="detail.run()">
				Try again
			</button>
		</div>

		<div class="org-detail__body">
			<OverviewTab v-if="activeTab === 'overview'"
				:org="full"
				:loading="detail.pending.value"
				@edit="emit('changed')" />

			<MembersTab v-else-if="activeTab === 'members'"
				:org="full"
				:members="members"
				@members-updated="onMembersUpdated" />

			<SubscriptionTab v-else-if="activeTab === 'subscription'"
				:org="full"
				@changed="emit('changed')" />

			<BackupsTab v-else-if="activeTab === 'backups'"
				:organization="full" />

			<HandoverTab v-else
				:organization="full"
				:members="members" />
		</div>
	</div>
</template>

<style scoped>
/* Layout only. */
.org-detail__body {
	padding-top: var(--iz-gap);
}

.org-detail__error {
	margin-top: var(--iz-gap);
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.org-detail__pending {
	padding: 20px 0;
}
</style>
