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

export interface TeamMember {
	uid: string
	displayName: string
	email?: string | null
}

export interface Team {
	id: number
	organizationId: number
	name: string
	description?: string | null
	createdBy: string
	createdAt: string
	updatedAt: string
	members: TeamMember[]
	memberCount: number
}

export interface ProjectTeamAssignment {
	projectId: number
	projectName: string
	team: Pick<Team, 'id' | 'name'> | null
}

export interface Plan {
	id: number
	name: string
	maxMembers: number
	maxProjects: number
	sharedStoragePerProject: number
	privateStoragePerUser: number
	/** Bytes per external collaborator; null uses the instance default. */
	externalStorageQuota?: number | null
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

/* ── Async jobs ───────────────────────────────────────────────────────────
 * Backups, rollbacks and account handovers are three separate services, but
 * they emit the same step and event records, so those two are shared.
 *
 * Every timestamp is the server's 'Y-m-d H:i:s' in UTC with no zone suffix.
 * Format them through src/lib/format.ts, which normalises that; `new Date(raw)`
 * parses it as local time and silently shifts it by the viewer's offset.
 */

/** One append-only log line. `sequenceNo` is the per-job ordering key. */
export interface JobEvent {
	id: number
	jobId: number
	sequenceNo: number
	/** Only handover events carry one; backup and rollback events are all null. */
	stepKey: string | null
	level: 'info' | 'warning' | 'error' | string
	message: string
	payload: Record<string, unknown> | null
	createdAt: string
}

/**
 * One unit of work. Every step is seeded `queued` when the job is created and
 * mutated in place, so the array is the job's whole plan, not just what ran.
 * Returned only by the single-job, create and retry endpoints — list endpoints
 * always answer `steps: []`.
 */
export interface JobStep {
	id: number
	jobId: number
	stepKey: string
	status: 'queued' | 'running' | 'completed' | 'failed' | 'skipped' | string
	attempt: number
	retriable: boolean
	result: Record<string, unknown> | null
	errorMessage: string | null
	startedAt: string | null
	finishedAt: string | null
	updatedAt: string
}

export interface BackupResult {
	artifactName?: string
	artifactSize?: number | null
	expiresAt?: string
	summary?: {
		counts?: Record<string, number>
		warnings?: string[]
	}
}

export interface BackupJob {
	jobId: number
	organizationId: number
	requestedByUid: string
	backupType: 'full' | 'incremental' | string
	/** 'manual' or 'scheduled'; scheduled jobs are requested by '__system__'. */
	triggerSource: 'manual' | 'scheduled' | string
	baselineJobId: number | null
	baseFullJobId: number | null
	scheduleKey: string | null
	status: 'queued' | 'running' | 'completed' | 'failed' | 'expired' | 'deleted' | string
	attempt: number
	options: Record<string, unknown> | null
	result: BackupResult | null
	errorMessage: string | null
	artifactName: string | null
	artifactSize: number | null
	createdAt: string
	updatedAt: string
	startedAt: string | null
	finishedAt: string | null
	expiresAt: string
	steps: JobStep[]
}

export interface RollbackResult {
	mode?: string
	sourceBackupJobId?: number
	canApply?: boolean
	validationErrors?: string[]
	warnings?: string[]
	impact?: Record<string, number>
	preRestoreBackupJobId?: number | null
}

export interface RollbackJob {
	jobId: number
	organizationId: number
	sourceBackupJobId: number
	requestedByUid: string
	mode: 'dry_run' | 'apply' | string
	status: 'queued' | 'running' | 'completed' | 'failed' | string
	attempt: number
	result: RollbackResult | null
	errorMessage: string | null
	/** The safety snapshot taken before an apply, if one was made. */
	preRestoreBackupJobId: number | null
	createdAt: string
	updatedAt: string
	startedAt: string | null
	finishedAt: string | null
	steps: JobStep[]
}

export interface HandoverJob {
	jobId: number
	organizationId: number
	sourceUserId: string
	targetUserId: string
	requestedByUserId: string
	status: 'queued' | 'running' | 'completed' | 'failed' | string
	dryRun: boolean
	removeSourceFromGroups: boolean
	remapDeckContent: boolean
	idempotencyKey: string | null
	requestFingerprint: string | null
	attempt: number
	result: Record<string, unknown> | null
	errorMessage: string | null
	createdAt: string
	updatedAt: string
	startedAt: string | null
	finishedAt: string | null
	steps: JobStep[]
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

/** An external collaborator of an organization, with the projects they are invited to. */
export interface ExternalCollaborator {
	userId: string
	displayName: string | null
	email: string | null
	company: string | null
	accountStatus: 'invited' | 'active' | 'suspended' | 'disabled' | null
	lastSeenAt: string | null
	projects: Array<{
		projectId: number
		projectName: string
		status: 'pending' | 'active'
		expiresAt: string | null
	}>
}

export interface ExternalActivity {
	id: number
	organizationId: number | null
	projectId: number | null
	userId: string
	actorId: string | null
	action: string
	details: Record<string, unknown> | null
	createdAt: string
	displayName: string
	actorName: string | null
	projectName: string | null
}

export interface ExternalSeats {
	used: number
	max: number | null
	externals: number
}
