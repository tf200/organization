<?php

/**
 * SPDX-FileCopyrightText: 2026 Custom Development
 * SPDX-License-Identifier: AGPL-3.0-only
 */
return [
    'routes' => [
        ['name' => 'Page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'Invite#show', 'url' => '/invite/{token}', 'verb' => 'GET'],
        ['name' => 'Invite#accept', 'url' => '/invite/{token}/accept', 'verb' => 'POST'],
        ['name' => 'BackupDownload#download', 'url' => '/organizations/{organizationId}/backups/jobs/{jobId}/download', 'verb' => 'GET'],
        ['name' => 'Contract#download', 'url' => '/organizations/{organizationId}/contracts/{contractId}/download', 'verb' => 'GET'],
        ['name' => 'Contract#signedDownload', 'url' => '/organizations/{organizationId}/contracts/{contractId}/signature-requests/{requestId}/download', 'verb' => 'GET'],
        ['name' => 'Contract#signaturePlacementPdf', 'url' => '/organizations/{organizationId}/contracts/{contractId}/signature-requests/{requestId}/placement.pdf', 'verb' => 'GET'],
    ],
    'ocs' => [

        // Organizations
        ['root' => '/apps/organization', 'name' => 'Organization#getOrganizations', 'url' => '/organizations', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Organization#getOrganization', 'url' => '/organizations/{organizationId}', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Organization#updateOrganization', 'url' => '/organizations/{organizationId}', 'verb' => 'PUT'],
        ['root' => '/apps/organization', 'name' => 'Organization#getOrganizationMembers', 'url' => '/organizations/{organizationId}/members', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Organization#searchAvailableUsers', 'url' => '/organizations/{organizationId}/available-users', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Organization#addOrganizationMember', 'url' => '/organizations/{organizationId}/members', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Organization#removeOrganizationMember', 'url' => '/organizations/{organizationId}/members/{userId}', 'verb' => 'DELETE'],
        ['root' => '/apps/organization', 'name' => 'Organization#createOrganizationUser', 'url' => '/organizations/{organizationId}/users', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Organization#handoverOrganizationMember', 'url' => '/organizations/{organizationId}/handover', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Organization#listHandoverJobs', 'url' => '/organizations/{organizationId}/handover/jobs', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Organization#getHandoverJob', 'url' => '/organizations/{organizationId}/handover/jobs/{jobId}', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Organization#retryHandoverJob', 'url' => '/organizations/{organizationId}/handover/jobs/{jobId}/retry', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Organization#listHandoverEvents', 'url' => '/organizations/{organizationId}/handover/jobs/{jobId}/events', 'verb' => 'GET'],

        // Backups
        ['root' => '/apps/organization', 'name' => 'Backup#listMyOrganizationBackupJobs', 'url' => '/backups/jobs/my-organization', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Backup#createBackupJob', 'url' => '/organizations/{organizationId}/backups/jobs', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Backup#listBackupJobs', 'url' => '/organizations/{organizationId}/backups/jobs', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Backup#getBackupJob', 'url' => '/organizations/{organizationId}/backups/jobs/{jobId}', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Backup#listBackupEvents', 'url' => '/organizations/{organizationId}/backups/jobs/{jobId}/events', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Backup#deleteBackupJob', 'url' => '/organizations/{organizationId}/backups/jobs/{jobId}', 'verb' => 'DELETE'],
        ['root' => '/apps/organization', 'name' => 'Backup#createRollbackJob', 'url' => '/organizations/{organizationId}/backups/rollback-jobs', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Backup#listRollbackJobs', 'url' => '/organizations/{organizationId}/backups/rollback-jobs', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Backup#getRollbackJob', 'url' => '/organizations/{organizationId}/backups/rollback-jobs/{jobId}', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Backup#listRollbackEvents', 'url' => '/organizations/{organizationId}/backups/rollback-jobs/{jobId}/events', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Organization#createOrganization', 'url' => '/organizations', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Organization#updateSubscription', 'url' => '/organizations/{organizationId}/subscription', 'verb' => 'PUT'],
        ['root' => '/apps/organization', 'name' => 'Organization#convertTrialToStandard', 'url' => '/organizations/{organizationId}/convert-trial', 'verb' => 'POST'],

        // Teams
        ['root' => '/apps/organization', 'name' => 'Team#index', 'url' => '/organizations/{organizationId}/teams', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Team#create', 'url' => '/organizations/{organizationId}/teams', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Team#update', 'url' => '/organizations/{organizationId}/teams/{teamId}', 'verb' => 'PUT'],
        ['root' => '/apps/organization', 'name' => 'Team#destroy', 'url' => '/organizations/{organizationId}/teams/{teamId}', 'verb' => 'DELETE'],
        ['root' => '/apps/organization', 'name' => 'Team#addMember', 'url' => '/organizations/{organizationId}/teams/{teamId}/members', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Team#removeMember', 'url' => '/organizations/{organizationId}/teams/{teamId}/members/{userId}', 'verb' => 'DELETE'],
        ['root' => '/apps/organization', 'name' => 'Team#projectTeams', 'url' => '/organizations/{organizationId}/project-teams', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Team#assignProjectTeam', 'url' => '/organizations/{organizationId}/projects/{projectId}/team', 'verb' => 'PUT'],

        // Contracts
        ['root' => '/apps/organization', 'name' => 'Contract#index', 'url' => '/organizations/{organizationId}/contracts', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Contract#create', 'url' => '/organizations/{organizationId}/contracts', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Contract#update', 'url' => '/organizations/{organizationId}/contracts/{contractId}', 'verb' => 'PUT'],
        ['root' => '/apps/organization', 'name' => 'Contract#replace', 'url' => '/organizations/{organizationId}/contracts/{contractId}/file', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Contract#destroy', 'url' => '/organizations/{organizationId}/contracts/{contractId}', 'verb' => 'DELETE'],
        ['root' => '/apps/organization', 'name' => 'Contract#signatureRequests', 'url' => '/organizations/{organizationId}/contracts/{contractId}/signature-requests', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Contract#createSignatureRequest', 'url' => '/organizations/{organizationId}/contracts/{contractId}/signature-requests', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Contract#sendSignatureRequest', 'url' => '/organizations/{organizationId}/contracts/{contractId}/signature-requests/{requestId}/send', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Contract#signaturePlacement', 'url' => '/organizations/{organizationId}/contracts/{contractId}/signature-requests/{requestId}/placement', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Contract#signaturePlacementData', 'url' => '/organizations/{organizationId}/contracts/{contractId}/signature-requests/{requestId}/placement-data', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Contract#saveSignaturePlacement', 'url' => '/organizations/{organizationId}/contracts/{contractId}/signature-requests/{requestId}/elements', 'verb' => 'PUT'],

        // Plans
        // Plans
        ['root' => '/apps/organization', 'name' => 'Plan#getPlans', 'url' => '/plans', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Plan#getPlan', 'url' => '/plans/{planId}', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'Plan#createPlan', 'url' => '/plans', 'verb' => 'POST'],
        ['root' => '/apps/organization', 'name' => 'Plan#updatePlan', 'url' => '/plans/{planId}', 'verb' => 'PUT'],
        ['root' => '/apps/organization', 'name' => 'Plan#deletePlan', 'url' => '/plans/{planId}', 'verb' => 'DELETE'],

        // Admin Settings
        ['root' => '/apps/organization', 'name' => 'AdminSettings#getTrialSettings', 'url' => '/admin/settings/trial', 'verb' => 'GET'],
        ['root' => '/apps/organization', 'name' => 'AdminSettings#saveTrialSettings', 'url' => '/admin/settings/trial', 'verb' => 'PUT'],

    ],
];
