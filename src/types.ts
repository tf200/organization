/**
 * Shared types.
 *
 * These live in a plain module rather than an SFC because Vue's compiler
 * rejects `export` statements inside `<script setup>` — a type declared there
 * cannot be imported by a sibling component.
 */

export type TabKey = 'organizations' | 'plans' | 'trial'

export interface Member {
	uid: string
	displayName: string
	email?: string
	role: 'admin' | 'member' | string
}

export interface Plan {
	id: number
	name: string
	maxMembers: number
	maxProjects: number
	sharedStoragePerProject: number
	privateStoragePerUser: number
	price: number
	currency: string
	isPublic: boolean
	subscriptionCount?: number
}

export interface Subscription {
	id: number
	status: string
	planName?: string
	maxMembers: number
	maxProjects: number
	startedAt?: string
	endedAt?: string
}

export interface Organization {
	id: number
	displayname: string
	type: 'standard' | 'trial' | string
	adminUid: string
	usercount: number
	contactFirstName?: string
	contactLastName?: string
	contactEmail?: string
	contactPhone?: string
	canAdd?: boolean
	canRemove?: boolean
	subscription: Subscription
	plan?: Pick<Plan, 'sharedStoragePerProject' | 'privateStoragePerUser'>
	members?: Member[]
}

/** Trial defaults, as returned by GET /admin/settings/trial. */
export interface TrialSettings {
	duration: string
	maxMembers: number
	maxProjects: number
	sharedStoragePerProject: number
	privateStoragePerUser: number
	price: number
	currency: string
	planName: string
}
