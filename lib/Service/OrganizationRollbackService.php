<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCA\Organization\Event\ExternalGrantActivatedEvent;
use OCA\Organization\Event\ExternalGrantRevokedEvent;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\ITempManager;

use Psr\Log\LoggerInterface;

class OrganizationRollbackService
{
    private const JOBS_TABLE = 'org_rb_jobs';
    private const STEPS_TABLE = 'org_rb_steps';
    private const EVENTS_TABLE = 'org_rb_events';

    private const MODE_DRY_RUN = 'dry_run';
    private const MODE_APPLY = 'apply';

    /** @var list<string> */
    private const STEP_ORDER = [
        'validate_source',
        'dry_run_preview',
        'snapshot_pre_restore',
        'restore_db',
        'restore_files',
        'finalize',
    ];

    /** @var list<string> */
    private const PROJECT_TABLES = [
        'custom_projects',
        'project_timeline_items',
        'project_notes',
        'project_activity_events',
        'project_deck_done_sync',
        'project_file_processing',
        'project_ocr_doc_types',
    ];

    /** @var list<string> */
    private const DECK_TABLES = [
        'deck_boards',
        'deck_stacks',
        'deck_cards',
        'deck_labels',
        'deck_board_acl',
        'deck_assigned_users',
        'deck_assigned_labels',
        'deck_attachment',
    ];

    public function __construct(
        private IDBConnection $db,
        private OrganizationBackupService $backupService,
        private ITempManager $tempManager,
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
        private ?OrganizationGroupService $organizationGroupService = null,
        private ?IEventDispatcher $eventDispatcher = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function createJob(
        int $organizationId,
        int $sourceBackupJobId,
        string $requestedByUid,
        string $mode = self::MODE_DRY_RUN,
    ): array {
        if ($organizationId <= 0) {
            throw new \InvalidArgumentException('organizationId must be a positive integer');
        }
        if ($sourceBackupJobId <= 0) {
            throw new \InvalidArgumentException('sourceBackupJobId must be a positive integer');
        }

        $requestedByUid = trim($requestedByUid);
        if ($requestedByUid === '') {
            throw new \InvalidArgumentException('requestedByUid must not be empty');
        }

        $resolvedMode = $this->normalizeMode($mode);
        $sourceJob = $this->validateSourceBackupJob($organizationId, $sourceBackupJobId);

        if ($resolvedMode === self::MODE_APPLY) {
            $dryRun = $this->findLatestCompletedDryRunJob($organizationId, $sourceBackupJobId);
            if ($dryRun === null) {
                throw new \InvalidArgumentException('Apply rollback requires a completed dry-run for the selected source backup');
            }

            $dryRunResult = $this->decodeJsonNullable($dryRun['result_json'] ?? null);
            if (!is_array($dryRunResult) || (($dryRunResult['canApply'] ?? false) !== true)) {
                throw new \InvalidArgumentException('Latest dry-run cannot be applied');
            }
        }

        if ($resolvedMode === self::MODE_APPLY && $this->hasInProgressApplyJob($organizationId)) {
            throw new \InvalidArgumentException('Another rollback apply job is already queued or running for this organization');
        }

        $now = $this->utcNow();
        $insert = $this->db->getQueryBuilder();
        $insert->insert(self::JOBS_TABLE)
            ->values([
                'organization_id' => $insert->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT),
                'source_backup_job_id' => $insert->createNamedParameter($sourceBackupJobId, IQueryBuilder::PARAM_INT),
                'requested_by_uid' => $insert->createNamedParameter($requestedByUid, IQueryBuilder::PARAM_STR),
                'mode' => $insert->createNamedParameter($resolvedMode, IQueryBuilder::PARAM_STR),
                'status' => $insert->createNamedParameter('queued', IQueryBuilder::PARAM_STR),
                'attempt' => $insert->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                'result_json' => $insert->createNamedParameter(null),
                'error_message' => $insert->createNamedParameter(null),
                'pre_restore_backup_job_id' => $insert->createNamedParameter(null),
                'created_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_STR),
                'updated_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_STR),
                'started_at' => $insert->createNamedParameter(null),
                'finished_at' => $insert->createNamedParameter(null),
            ])
            ->executeStatement();

        $jobId = (int) $insert->getLastInsertId();
        if ($jobId <= 0) {
            throw new \RuntimeException('Failed to create rollback job');
        }

        foreach (self::STEP_ORDER as $stepKey) {
            $this->upsertStep($jobId, $stepKey, [
                'status' => 'queued',
                'attempt' => 1,
                'retriable' => true,
                'error_message' => null,
                'result_json' => null,
                'started_at' => null,
                'finished_at' => null,
                'updated_at' => $now,
            ]);
        }

        $this->insertEvent($jobId, 'info', 'Rollback job queued', [
            'organizationId' => $organizationId,
            'sourceBackupJobId' => $sourceBackupJobId,
            'sourceBackupType' => (string) ($sourceJob['backupType'] ?? ''),
            'mode' => $resolvedMode,
        ]);

        $row = $this->getJobRowById($jobId);
        if ($row === null) {
            throw new \RuntimeException('Failed to load rollback job after creation');
        }

        return $this->mapJobRow($row, true);
    }

    public function getOldestQueuedJobId(): ?int
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('id')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('queued', IQueryBuilder::PARAM_STR)))
            ->orderBy('id', 'ASC')
            ->setMaxResults(1)
            ->executeQuery();

        $id = $result->fetchOne();
        $result->closeCursor();

        return $id === false ? null : (int) $id;
    }

    public function markJobFailedFromWorker(int $jobId, \Throwable $e): void
    {
        $failedAt = $this->utcNow();
        try {
            $this->updateJob($jobId, [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => $failedAt,
                'updated_at' => $failedAt,
            ]);
            $this->insertEvent($jobId, 'error', 'Rollback failed before execution start', [
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $inner) {
            $this->logger->error('Failed to mark rollback job as failed after worker exception', [
                'exception' => $inner,
                'jobId' => $jobId,
            ]);
        }

        $this->logger->error('Rollback worker execution failed', [
            'exception' => $e,
            'jobId' => $jobId,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function runJob(int $jobId): array
    {
        $job = $this->getJobRowById($jobId);
        if ($job === null) {
            throw new \InvalidArgumentException('Rollback job not found');
        }

        $status = (string) ($job['status'] ?? '');
        if (!in_array($status, ['queued', 'failed'], true)) {
            return $this->mapJobRow($job, true);
        }

        $mode = $this->normalizeMode((string) ($job['mode'] ?? self::MODE_DRY_RUN));
        $now = $this->utcNow();
        $this->updateJob($jobId, [
            'status' => 'running',
            'mode' => $mode,
            'started_at' => $job['started_at'] ?? $now,
            'finished_at' => null,
            'error_message' => null,
            'result_json' => null,
            'pre_restore_backup_job_id' => null,
            'updated_at' => $now,
        ]);
        $this->insertEvent($jobId, 'info', 'Rollback execution started', [
            'mode' => $mode,
        ]);

        $tmpZipPath = null;
        $validationFailureResult = null;
        try {
            $organizationId = (int) $job['organization_id'];
            $sourceBackupJobId = (int) $job['source_backup_job_id'];
            $requestedByUid = (string) $job['requested_by_uid'];

            $this->markStepRunning($jobId, 'validate_source');
            $sourceJob = $this->validateSourceBackupJob($organizationId, $sourceBackupJobId);
            $artifactName = (string) ($sourceJob['artifactName'] ?? '');
            if ($artifactName === '') {
                throw new \RuntimeException('Source backup artifact is not available');
            }

            $tmpZipPath = $this->copyArtifactToTempPath($organizationId, $artifactName);
            $archive = $this->loadArchivePayload($tmpZipPath);
            $this->markStepCompleted($jobId, 'validate_source', [
                'sourceBackupJobId' => $sourceBackupJobId,
                'artifactName' => $artifactName,
                'backupType' => (string) ($sourceJob['backupType'] ?? 'full'),
                'mode' => $mode,
            ]);

            $this->markStepRunning($jobId, 'dry_run_preview');
            $preview = $this->buildDryRunPreview($organizationId, $archive);
            $this->markStepCompleted($jobId, 'dry_run_preview', $preview);

            $snapshotJobId = null;
            $restoreDbResult = null;
            $restoreFilesResult = null;

            if ($mode === self::MODE_DRY_RUN) {
                $this->markStepSkipped($jobId, 'snapshot_pre_restore', ['reason' => 'dry-run mode']);
                $this->markStepSkipped($jobId, 'restore_db', ['reason' => 'dry-run mode']);
                $this->markStepSkipped($jobId, 'restore_files', ['reason' => 'dry-run mode']);
            } else {
                if (($preview['canApply'] ?? false) !== true) {
                    $validationFailureResult = $this->buildApplyValidationFailureResult($preview, $mode, $sourceBackupJobId);
                    throw new \RuntimeException('Dry-run validation failed; rollback apply is blocked');
                }

                $this->markStepRunning($jobId, 'snapshot_pre_restore');
                $snapshotJob = $this->backupService->createJob(
                    $organizationId,
                    $requestedByUid,
                    [
                        'includeProjectCreator' => true,
                        'includeDeck' => true,
                        'includeSharedFiles' => true,
                        'excludePrivateData' => false,
                        'triggeredByRollbackJobId' => $jobId,
                    ],
                    'full',
                    'manual',
                );
                $snapshotJobId = isset($snapshotJob['jobId']) ? (int) $snapshotJob['jobId'] : null;
                if ($snapshotJobId === null || $snapshotJobId <= 0) {
                    throw new \RuntimeException('Failed to create pre-restore snapshot backup');
                }

                $snapshotResult = $this->backupService->runJob($snapshotJobId);
                if (($snapshotResult['status'] ?? '') !== 'completed') {
                    throw new \RuntimeException('Pre-restore snapshot backup did not complete successfully');
                }

                $this->updateJob($jobId, [
                    'pre_restore_backup_job_id' => $snapshotJobId,
                    'updated_at' => $this->utcNow(),
                ]);
                $this->markStepCompleted($jobId, 'snapshot_pre_restore', [
                    'snapshotBackupJobId' => $snapshotJobId,
                    'status' => 'completed',
                ]);

                $this->markStepRunning($jobId, 'restore_db');
                $restoreDbResult = $this->applyDatabaseRestore($organizationId, $archive);
                $this->markStepCompleted($jobId, 'restore_db', $restoreDbResult);

                $this->markStepRunning($jobId, 'restore_files');
                $restoreFilesResult = $this->applyFileRestore($organizationId, $tmpZipPath, $archive);
                $this->markStepCompleted($jobId, 'restore_files', $restoreFilesResult);
            }

            $this->markStepRunning($jobId, 'finalize');
            $result = [
                'mode' => $mode,
                'sourceBackupJobId' => $sourceBackupJobId,
                'canApply' => (bool) ($preview['canApply'] ?? false),
                'validationErrors' => $preview['errors'] ?? [],
                'warnings' => $preview['warnings'] ?? [],
                'impact' => $preview['impact'] ?? [],
                'preRestoreBackupJobId' => $snapshotJobId,
                'restoreDb' => $restoreDbResult,
                'restoreFiles' => $restoreFilesResult,
            ];
            $this->markStepCompleted($jobId, 'finalize', $result);

            $completedAt = $this->utcNow();
            $this->updateJob($jobId, [
                'status' => 'completed',
                'result_json' => json_encode($result, JSON_THROW_ON_ERROR),
                'error_message' => null,
                'pre_restore_backup_job_id' => $snapshotJobId,
                'finished_at' => $completedAt,
                'updated_at' => $completedAt,
            ]);
            $this->insertEvent($jobId, 'info', 'Rollback completed', [
                'mode' => $mode,
                'preRestoreBackupJobId' => $snapshotJobId,
                'canApply' => $result['canApply'],
            ]);

            $jobRow = $this->getJobRowById($jobId);
            if ($jobRow === null) {
                throw new \RuntimeException('Failed to load completed rollback job');
            }

            return $this->mapJobRow($jobRow, true);
        } catch (\Throwable $e) {
            $failedAt = $this->utcNow();
            $errorResultJson = $validationFailureResult !== null ? json_encode($validationFailureResult, JSON_THROW_ON_ERROR) : null;
            $eventPayload = ['error' => $e->getMessage()];
            if ($validationFailureResult !== null) {
                $eventPayload = array_merge($eventPayload, $this->buildApplyValidationFailureEventPayload($validationFailureResult));
            }
            $this->updateJob($jobId, [
                'status' => 'failed',
                'result_json' => $errorResultJson,
                'error_message' => $e->getMessage(),
                'finished_at' => $failedAt,
                'updated_at' => $failedAt,
            ]);
            $this->insertEvent($jobId, 'error', 'Rollback failed', $eventPayload);

            $this->logger->error('Organization rollback failed', [
                'exception' => $e,
                'jobId' => $jobId,
            ]);

            $jobRow = $this->getJobRowById($jobId);
            if ($jobRow === null) {
                throw new \RuntimeException('Failed to load failed rollback job');
            }

            return $this->mapJobRow($jobRow, true);
        } finally {
            if (is_string($tmpZipPath) && $tmpZipPath !== '') {
                @unlink($tmpZipPath);
            }
        }
    }

    /**
     * @return array{jobs:list<array<string,mixed>>,limit:int,offset:int}
     */
    public function listJobs(int $organizationId, ?string $status, int $limit, int $offset): array
    {
        $effectiveLimit = max(1, min($limit, 100));
        $effectiveOffset = max(0, $offset);
        $effectiveStatus = trim((string) ($status ?? ''));

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)));

        if ($effectiveStatus !== '') {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($effectiveStatus, IQueryBuilder::PARAM_STR)));
        }

        $result = $qb->orderBy('id', 'DESC')
            ->setFirstResult($effectiveOffset)
            ->setMaxResults($effectiveLimit)
            ->executeQuery();

        $jobs = [];
        while (($row = $result->fetch()) !== false) {
            $jobs[] = $this->mapJobRow($row, false);
        }
        $result->closeCursor();

        return [
            'jobs' => $jobs,
            'limit' => $effectiveLimit,
            'offset' => $effectiveOffset,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getJob(int $organizationId, int $jobId): array
    {
        $row = $this->getJobRowByIdAndOrganization($jobId, $organizationId);
        if ($row === null) {
            throw new \InvalidArgumentException('Rollback job not found');
        }

        return $this->mapJobRow($row, true);
    }

    /**
     * @return array{events:list<array<string,mixed>>,limit:int,offset:int}
     */
    public function listEvents(int $organizationId, int $jobId, int $limit, int $offset): array
    {
        $job = $this->getJobRowByIdAndOrganization($jobId, $organizationId);
        if ($job === null) {
            throw new \InvalidArgumentException('Rollback job not found');
        }

        $effectiveLimit = max(1, min($limit, 200));
        $effectiveOffset = max(0, $offset);

        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::EVENTS_TABLE)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
            ->orderBy('sequence_no', 'ASC')
            ->setFirstResult($effectiveOffset)
            ->setMaxResults($effectiveLimit)
            ->executeQuery();

        $events = [];
        while (($row = $result->fetch()) !== false) {
            $events[] = $this->mapEventRow($row);
        }
        $result->closeCursor();

        return [
            'events' => $events,
            'limit' => $effectiveLimit,
            'offset' => $effectiveOffset,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function mapJobRow(array $row, bool $includeSteps): array
    {
        $jobId = (int) ($row['id'] ?? 0);
        return [
            'jobId' => $jobId,
            'organizationId' => (int) ($row['organization_id'] ?? 0),
            'sourceBackupJobId' => (int) ($row['source_backup_job_id'] ?? 0),
            'requestedByUid' => (string) ($row['requested_by_uid'] ?? ''),
            'mode' => $this->normalizeMode((string) ($row['mode'] ?? self::MODE_DRY_RUN)),
            'status' => (string) ($row['status'] ?? ''),
            'attempt' => (int) ($row['attempt'] ?? 1),
            'result' => $this->decodeJsonNullable($row['result_json'] ?? null),
            'errorMessage' => $row['error_message'] ?? null,
            'preRestoreBackupJobId' => isset($row['pre_restore_backup_job_id']) ? (int) $row['pre_restore_backup_job_id'] : null,
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
            'startedAt' => $row['started_at'] ?? null,
            'finishedAt' => $row['finished_at'] ?? null,
            'steps' => $includeSteps ? $this->listMappedStepsByJobId($jobId) : [],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listMappedStepsByJobId(int $jobId): array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::STEPS_TABLE)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')
            ->executeQuery();

        $steps = [];
        while (($row = $result->fetch()) !== false) {
            $steps[] = [
                'id' => (int) ($row['id'] ?? 0),
                'jobId' => (int) ($row['job_id'] ?? 0),
                'stepKey' => (string) ($row['step_key'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'attempt' => (int) ($row['attempt'] ?? 1),
                'retriable' => ((int) ($row['retriable'] ?? 1)) === 1,
                'result' => $this->decodeJsonNullable($row['result_json'] ?? null),
                'errorMessage' => $row['error_message'] ?? null,
                'startedAt' => $row['started_at'] ?? null,
                'finishedAt' => $row['finished_at'] ?? null,
                'updatedAt' => $row['updated_at'] ?? null,
            ];
        }
        $result->closeCursor();

        return $steps;
    }

    /**
     * @return array<string,mixed>
     */
    private function mapEventRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'jobId' => (int) ($row['job_id'] ?? 0),
            'sequenceNo' => (int) ($row['sequence_no'] ?? 1),
            'stepKey' => $row['step_key'] ?? null,
            'level' => (string) ($row['level'] ?? 'info'),
            'message' => (string) ($row['message'] ?? ''),
            'payload' => $this->decodeJsonNullable($row['payload_json'] ?? null),
            'createdAt' => $row['created_at'] ?? null,
        ];
    }

    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return $mode === self::MODE_APPLY ? self::MODE_APPLY : self::MODE_DRY_RUN;
    }

    /**
     * @return array<string,mixed>
     */
    private function validateSourceBackupJob(int $organizationId, int $sourceBackupJobId): array
    {
        try {
            $sourceJob = $this->backupService->getJob($organizationId, $sourceBackupJobId);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Source backup job not found');
        }

        if (($sourceJob['status'] ?? '') !== 'completed') {
            throw new \InvalidArgumentException('Source backup job must be completed');
        }

        if (($sourceJob['backupType'] ?? '') !== 'full') {
            throw new \InvalidArgumentException('Only full backup jobs can be used for rollback');
        }

        $artifactName = trim((string) ($sourceJob['artifactName'] ?? ''));
        if ($artifactName === '') {
            throw new \InvalidArgumentException('Source backup artifact is missing');
        }

        $expiresAt = trim((string) ($sourceJob['expiresAt'] ?? ''));
        if ($expiresAt !== '' && $this->isExpired($expiresAt)) {
            throw new \InvalidArgumentException('Source backup artifact has expired');
        }

        if (!$this->backupService->artifactExists($organizationId, $artifactName)) {
            throw new \InvalidArgumentException('Source backup artifact file is not available');
        }

        return $sourceJob;
    }

    private function isExpired(string $dateValue): bool
    {
        try {
            $value = new \DateTimeImmutable($dateValue, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return false;
        }

        return $value < new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function hasInProgressApplyJob(int $organizationId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('id')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter(self::MODE_APPLY, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['queued', 'running'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->setMaxResults(1)
            ->executeQuery();

        $id = $result->fetchOne();
        $result->closeCursor();

        return $id !== false;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findLatestCompletedDryRunJob(int $organizationId, int $sourceBackupJobId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('source_backup_job_id', $qb->createNamedParameter($sourceBackupJobId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter(self::MODE_DRY_RUN, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('completed', IQueryBuilder::PARAM_STR)))
            ->orderBy('finished_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery();

        $row = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $row;
    }

    private function copyArtifactToTempPath(int $organizationId, string $artifactName): string
    {
        $stream = $this->backupService->openArtifactStream($organizationId, $artifactName);
        $tmpPath = $this->createTempFilePath('.zip');
        $out = fopen($tmpPath, 'wb');
        if ($out === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new \RuntimeException('Failed to open temporary zip path');
        }

        try {
            if (stream_copy_to_stream($stream, $out) === false) {
                throw new \RuntimeException('Failed to copy backup artifact into temporary zip');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            fclose($out);
        }

        return $tmpPath;
    }

    private function createTempFilePath(string $suffix): string
    {
        $tmp = $this->tempManager->getTemporaryFile($suffix);
        if ($tmp === false) {
            throw new \RuntimeException('Failed to allocate temporary file');
        }
        return (string) $tmp;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadArchivePayload(string $zipPath): array
    {
        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new \RuntimeException('Failed to open rollback source archive');
        }

        try {
            $payload = [
                'manifest' => $this->readJsonFromZip($zip, 'manifest.json', null),
                'db' => [
                    'organization' => $this->readJsonFromZip($zip, 'db/organization.json', null),
                    'organizationMembers' => $this->readJsonFromZip($zip, 'db/organization_members.json', []),
                    'subscriptions' => $this->readJsonFromZip($zip, 'db/subscriptions.json', []),
                    'subscriptionsHistory' => $this->readJsonFromZip($zip, 'db/subscriptions_history.json', []),
                    'plans' => $this->readJsonFromZip($zip, 'db/plans.json', []),
                    // Null for archives made before externals existed: their grants stay as they are.
                    'externalGrants' => $this->readJsonFromZip($zip, 'db/external_grants.json', null),
                    'customProjects' => $this->readJsonFromZip($zip, 'db/projectcreator/custom_projects.json', []),
                    'projectTimeline' => $this->readJsonFromZip($zip, 'db/projectcreator/project_timeline_items.json', []),
                    'projectNotesPublic' => $this->readJsonFromZip($zip, 'db/projectcreator/project_notes_public.json', []),
                    'projectNotesPrivate' => $this->readJsonFromZip($zip, 'db/projectcreator/project_notes_private.json', []),
                    'projectActivity' => $this->readJsonFromZip($zip, 'db/projectcreator/project_activity_events.json', []),
                    'projectDoneSync' => $this->readJsonFromZip($zip, 'db/projectcreator/project_deck_done_sync.json', []),
                    'projectFileProcessing' => $this->readJsonFromZip($zip, 'db/projectcreator/project_file_processing.json', []),
                    'projectOcrDocTypes' => $this->readJsonFromZip($zip, 'db/projectcreator/project_ocr_doc_types.json', []),
                    'deckBundles' => $this->readDeckBundles($zip),
                ],
                'filesByProject' => $this->collectArchiveFilesByProject($zip),
            ];

            return $payload;
        } finally {
            $zip->close();
        }
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    private function readJsonFromZip(\ZipArchive $zip, string $path, $default)
    {
        $content = $zip->getFromName($path);
        if ($content === false) {
            return $default;
        }

        try {
            return json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readDeckBundles(\ZipArchive $zip): array
    {
        $bundles = [];
        $numFiles = (int) $zip->numFiles;
        for ($i = 0; $i < $numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_starts_with($name, 'deck/boards/') || !str_ends_with($name, '.json')) {
                continue;
            }

            $decoded = $this->readJsonFromZip($zip, $name, null);
            if (is_array($decoded)) {
                $bundles[] = $decoded;
            }
        }

        return $bundles;
    }

    /**
     * @return array<int,list<array{zipPath:string,relativePath:string,size:int}>>
     */
    private function collectArchiveFilesByProject(\ZipArchive $zip): array
    {
        $filesByProject = [];
        $numFiles = (int) $zip->numFiles;
        for ($i = 0; $i < $numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_starts_with($name, 'files/projects/') || str_ends_with($name, '/')) {
                continue;
            }

            $segments = explode('/', $name);
            if (count($segments) < 5) {
                continue;
            }

            $projectId = (int) $segments[2];
            if ($projectId <= 0) {
                continue;
            }

            $relativeSegments = array_slice($segments, 4);
            if ($relativeSegments === []) {
                continue;
            }

            $relativePath = implode('/', $relativeSegments);
            $stat = $zip->statIndex($i);
            $size = is_array($stat) && isset($stat['size']) ? (int) $stat['size'] : 0;

            $filesByProject[$projectId] ??= [];
            $filesByProject[$projectId][] = [
                'zipPath' => $name,
                'relativePath' => $relativePath,
                'size' => $size,
            ];
        }

        return $filesByProject;
    }

    /**
     * @param array<string,mixed> $archive
     * @return array<string,mixed>
     */
    private function buildDryRunPreview(int $organizationId, array $archive): array
    {
        $errors = [];
        $warnings = [];

        $db = is_array($archive['db'] ?? null) ? $archive['db'] : [];
        $orgRow = $db['organization'] ?? null;
        if (!is_array($orgRow)) {
            $errors[] = 'Archive is missing db/organization.json';
        } elseif ((int) ($orgRow['id'] ?? 0) !== $organizationId) {
            $errors[] = 'Archive organization does not match target organization';
        }

        $subscriptions = is_array($db['subscriptions'] ?? null) ? $db['subscriptions'] : [];
        $planIds = array_values(array_unique(array_filter(array_map(static fn (array $row): int => isset($row['plan_id']) ? (int) $row['plan_id'] : 0, $subscriptions), static fn (int $id): bool => $id > 0)));
        $missingPlanIds = $this->findMissingPlanIds($planIds);
        if ($missingPlanIds !== []) {
            $errors[] = sprintf('Referenced plan IDs are missing: %s', implode(', ', array_map(static fn (int $id): string => (string) $id, $missingPlanIds)));
        }

        $restoredMembers = is_array($db['organizationMembers'] ?? null) ? $db['organizationMembers'] : [];
        $conflictingMembers = $this->findConflictingOrganizationMemberships($organizationId, $restoredMembers);
        if ($conflictingMembers !== []) {
            $errors[] = sprintf('Some users currently belong to a different organization: %s', implode(', ', $conflictingMembers));
        }

        $restoredSubIds = array_values(array_unique(array_filter(array_map(static fn (array $row): int => isset($row['id']) ? (int) $row['id'] : 0, $subscriptions), static fn (int $id): bool => $id > 0)));
        $subCollisions = $this->findSubscriptionIdCollisions($organizationId, $restoredSubIds);
        if ($subCollisions !== []) {
            $errors[] = sprintf('Subscription IDs already belong to another organization: %s', implode(', ', array_map(static fn (int $id): string => (string) $id, $subCollisions)));
        }

        $customProjects = is_array($db['customProjects'] ?? null) ? $db['customProjects'] : [];
        $projectRowsById = [];
        foreach ($customProjects as $project) {
            if (!is_array($project)) {
                continue;
            }
            $projectId = isset($project['id']) ? (int) $project['id'] : 0;
            if ($projectId > 0) {
                $projectRowsById[$projectId] = $project;
            }
        }

        $filesByProject = is_array($archive['filesByProject'] ?? null) ? $archive['filesByProject'] : [];
        foreach ($filesByProject as $projectId => $files) {
            if (!isset($projectRowsById[(int) $projectId])) {
                $errors[] = sprintf('Files payload references missing project %d', (int) $projectId);
                continue;
            }

            $project = $projectRowsById[(int) $projectId];
            $folder = $this->resolveProjectSharedFolder($project);
            if ($folder === null) {
                if ($this->canProvisionProjectSharedFolder($project)) {
                    $warnings[] = sprintf('Shared folder will be recreated for project %d', (int) $projectId);
                } else {
                    $errors[] = sprintf('Shared folder is not resolvable for project %d', (int) $projectId);
                }
                continue;
            }

            if (count($files) === 0) {
                $warnings[] = sprintf('Project %d has no file entries in archive', (int) $projectId);
            }
        }

        $externalGrants = is_array($db['externalGrants'] ?? null) ? $db['externalGrants'] : null;
        $skippedExternals = $externalGrants === null ? [] : $this->findGoneExternals($externalGrants);
        if ($externalGrants === null) {
            $warnings[] = 'The archive predates external collaborators; their current access is kept';
        } elseif ($skippedExternals !== []) {
            $warnings[] = sprintf('Access of %d deleted external account(s) is not restored: %s', count($skippedExternals), implode(', ', $skippedExternals));
        }

        $deckBundles = is_array($db['deckBundles'] ?? null) ? $db['deckBundles'] : [];
        $requiredTables = [];
        if ($subscriptions !== [] || is_array($db['subscriptionsHistory'] ?? null) && $db['subscriptionsHistory'] !== []) {
            $requiredTables = array_merge($requiredTables, ['subscriptions', 'subscriptions_history']);
        }
        if ($customProjects !== [] || $filesByProject !== []) {
            $requiredTables = array_merge($requiredTables, self::PROJECT_TABLES);
        }
        if ($deckBundles !== []) {
            $requiredTables = array_merge($requiredTables, self::DECK_TABLES);
        }
        $requiredTables = array_values(array_unique($requiredTables));
        foreach ($requiredTables as $table) {
            if (!$this->tableExists($table)) {
                $errors[] = sprintf('Required table "%s" is unavailable on this instance', $table);
            }
        }

        $fileCount = 0;
        foreach ($filesByProject as $entries) {
            if (is_array($entries)) {
                $fileCount += count($entries);
            }
        }

        return [
            'canApply' => $errors === [],
            'errors' => $errors,
            'warnings' => array_values(array_unique($warnings)),
            'impact' => [
                'members' => count($restoredMembers),
                'externalGrants' => $externalGrants === null ? 0 : count($externalGrants) - $this->countGrantsOf($externalGrants, $skippedExternals),
                'subscriptions' => count($subscriptions),
                'projects' => count($customProjects),
                'deckBoards' => count($deckBundles),
                'projectFiles' => $fileCount,
                'projectNotesPublic' => is_array($db['projectNotesPublic'] ?? null) ? count($db['projectNotesPublic']) : 0,
                'projectNotesPrivate' => is_array($db['projectNotesPrivate'] ?? null) ? count($db['projectNotesPrivate']) : 0,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $preview
     * @return array<string,mixed>
     */
    private function buildApplyValidationFailureResult(array $preview, string $mode, int $sourceBackupJobId): array
    {
        return [
            'mode' => $mode,
            'sourceBackupJobId' => $sourceBackupJobId,
            'canApply' => (bool) ($preview['canApply'] ?? false),
            'validationErrors' => array_values(array_filter(
                is_array($preview['errors'] ?? null) ? $preview['errors'] : [],
                static fn ($value): bool => is_string($value) && trim($value) !== '',
            )),
            'warnings' => array_values(array_filter(
                is_array($preview['warnings'] ?? null) ? $preview['warnings'] : [],
                static fn ($value): bool => is_string($value) && trim($value) !== '',
            )),
            'impact' => is_array($preview['impact'] ?? null) ? $preview['impact'] : [],
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function buildApplyValidationFailureEventPayload(array $result): array
    {
        return [
            'mode' => (string) ($result['mode'] ?? self::MODE_APPLY),
            'sourceBackupJobId' => (int) ($result['sourceBackupJobId'] ?? 0),
            'canApply' => (bool) ($result['canApply'] ?? false),
            'validationErrors' => is_array($result['validationErrors'] ?? null) ? $result['validationErrors'] : [],
            'warnings' => is_array($result['warnings'] ?? null) ? $result['warnings'] : [],
            'impact' => is_array($result['impact'] ?? null) ? $result['impact'] : [],
        ];
    }

    /**
     * @param list<int> $planIds
     * @return list<int>
     */
    private function findMissingPlanIds(array $planIds): array
    {
        $planIds = array_values(array_unique(array_filter($planIds, static fn (int $id): bool => $id > 0)));
        if ($planIds === []) {
            return [];
        }

        $existing = [];
        foreach (array_chunk($planIds, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id')
                ->from('plans')
                ->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeQuery();
            while (($row = $result->fetch()) !== false) {
                $existing[] = (int) ($row['id'] ?? 0);
            }
            $result->closeCursor();
        }

        $existing = array_values(array_unique(array_filter($existing, static fn (int $id): bool => $id > 0)));
        sort($existing);

        $missing = array_values(array_diff($planIds, $existing));
        sort($missing);
        return $missing;
    }

    /**
     * @param list<array<string,mixed>> $members
     * @return list<string>
     */
    private function findConflictingOrganizationMemberships(int $organizationId, array $members): array
    {
        $userIds = [];
        foreach ($members as $member) {
            if (!is_array($member)) {
                continue;
            }
            $userId = trim((string) ($member['user_uid'] ?? ''));
            if ($userId !== '') {
                $userIds[] = $userId;
            }
        }

        $userIds = array_values(array_unique($userIds));
        if ($userIds === []) {
            return [];
        }

        $conflicting = [];
        foreach (array_chunk($userIds, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('user_uid', 'organization_id')
                ->from('organization_members')
                ->where($qb->expr()->in('user_uid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($qb->expr()->neq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
                ->executeQuery();

            while (($row = $result->fetch()) !== false) {
                $uid = trim((string) ($row['user_uid'] ?? ''));
                if ($uid !== '') {
                    $conflicting[] = $uid;
                }
            }
            $result->closeCursor();
        }

        $conflicting = array_values(array_unique($conflicting));
        sort($conflicting);
        return $conflicting;
    }

    /**
     * @param list<int> $subscriptionIds
     * @return list<int>
     */
    private function findSubscriptionIdCollisions(int $organizationId, array $subscriptionIds): array
    {
        $subscriptionIds = array_values(array_unique(array_filter($subscriptionIds, static fn (int $id): bool => $id > 0)));
        if ($subscriptionIds === []) {
            return [];
        }

        $collisions = [];
        foreach (array_chunk($subscriptionIds, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id')
                ->from('subscriptions')
                ->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->neq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
                ->executeQuery();

            while (($row = $result->fetch()) !== false) {
                $id = isset($row['id']) ? (int) $row['id'] : 0;
                if ($id > 0) {
                    $collisions[] = $id;
                }
            }
            $result->closeCursor();
        }

        $collisions = array_values(array_unique($collisions));
        sort($collisions);
        return $collisions;
    }

    private function tableExists(string $table): bool
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select($qb->createFunction('1'))
                ->from($table)
                ->setMaxResults(1)
                ->executeQuery();
            $result->closeCursor();
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Rollback table availability check failed', [
                'table' => $table,
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * @param array<string,mixed> $archive
     * @return array<string,mixed>
     */
    private function applyDatabaseRestore(int $organizationId, array $archive): array
    {
        $db = is_array($archive['db'] ?? null) ? $archive['db'] : [];

        $this->db->beginTransaction();
        try {
            $organizationResult = $this->restoreOrganizationRow($organizationId, $db);
            $membersResult = $this->restoreOrganizationMembers($organizationId, $db);
            $subscriptionsResult = $this->restoreSubscriptions($organizationId, $db);
            $projectResult = $this->restoreProjectCreatorData($organizationId, $db);
            $deckResult = $this->restoreDeckData($organizationId, $db, $projectResult);
            $externalsResult = $this->restoreExternalGrants($organizationId, $db);

            $this->db->commit();
            // Members may have joined or left with the restore.
            $this->organizationGroupService?->syncAll();
            $this->reconcileExternalAccess($externalsResult['before'], $externalsResult['after']);
            unset($externalsResult['before'], $externalsResult['after']);

            return [
                'organization' => $organizationResult,
                'members' => $membersResult,
                'subscriptions' => $subscriptionsResult,
                'projectCreator' => $projectResult,
                'deck' => $deckResult,
                'externals' => $externalsResult,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Puts the organization's external grants back as they were. Grants of
     * accounts deleted since the backup are left out, as there is nobody to
     * give them back to.
     *
     * @param array<string,mixed> $db
     * @return array{replaced: int, skipped: list<string>, before: array<string,array<string,mixed>>, after: array<string,array<string,mixed>>}
     */
    private function restoreExternalGrants(int $organizationId, array $db): array
    {
        $grants = is_array($db['externalGrants'] ?? null) ? $db['externalGrants'] : null;
        if ($grants === null || !$this->tableExists('organization_project_externals')) {
            return ['replaced' => 0, 'skipped' => [], 'before' => [], 'after' => []];
        }

        $before = $this->activeExternalGrants($organizationId);
        $skipped = $this->findGoneExternals($grants);

        $delete = $this->db->getQueryBuilder();
        $delete->delete('organization_project_externals')
            ->where($delete->expr()->eq('organization_id', $delete->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        $rows = [];
        foreach ($grants as $grant) {
            if (!is_array($grant) || in_array((string) ($grant['user_uid'] ?? ''), $skipped, true)) {
                continue;
            }
            $grant['organization_id'] = $organizationId;
            $rows[] = $grant;
        }
        $inserted = $this->insertRows('organization_project_externals', $rows);

        return [
            'replaced' => $inserted,
            'skipped' => $skipped,
            'before' => $before,
            'after' => $this->activeExternalGrants($organizationId),
        ];
    }

    /**
     * Active grants keyed by project and user.
     *
     * @return array<string,array<string,mixed>>
     */
    private function activeExternalGrants(int $organizationId): array
    {
        $active = [];
        foreach ($this->fetchAllWhereInt('organization_project_externals', 'organization_id', $organizationId) as $row) {
            if (($row['status'] ?? '') === 'active') {
                $active[$row['project_id'] . ':' . $row['user_uid']] = $row;
            }
        }
        return $active;
    }

    /**
     * Takes externals off projects they no longer have access to after the
     * restore, and puts back those who have it again.
     *
     * @param array<string,array<string,mixed>> $before
     * @param array<string,array<string,mixed>> $after
     */
    private function reconcileExternalAccess(array $before, array $after): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }

        foreach (array_diff_key($before, $after) as $row) {
            $this->dispatchSafely(new ExternalGrantRevokedEvent(
                (int) $row['organization_id'],
                (int) $row['project_id'],
                (string) $row['user_uid'],
                ExternalGrantRevokedEvent::REASON_REVOKED,
            ));
        }
        foreach (array_diff_key($after, $before) as $row) {
            $this->dispatchSafely(new ExternalGrantActivatedEvent(
                (int) $row['organization_id'],
                (int) $row['project_id'],
                (string) $row['user_uid'],
                $this->decodeList($row['functional_role_keys'] ?? null),
                $this->decodeList($row['drasci_roles'] ?? null),
            ));
        }
    }

    private function dispatchSafely(ExternalGrantActivatedEvent|ExternalGrantRevokedEvent $event): void
    {
        try {
            $this->eventDispatcher?->dispatchTyped($event);
        } catch (\Throwable $e) {
            $this->logger->error('Rollback could not update the project membership of an external collaborator', [
                'projectId' => $event->getProjectId(),
                'userId' => $event->getUserId(),
                'exception' => $e,
            ]);
        }
    }

    /** @return string[] */
    private function decodeList(mixed $json): array
    {
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : [];
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /**
     * External accounts in the archive's grants that no longer exist.
     *
     * @param array<mixed> $grants
     * @return list<string>
     */
    private function findGoneExternals(array $grants): array
    {
        $uids = [];
        foreach ($grants as $grant) {
            $uid = is_array($grant) ? trim((string) ($grant['user_uid'] ?? '')) : '';
            if ($uid !== '') {
                $uids[$uid] = true;
            }
        }
        if ($uids === [] || !$this->tableExists('organization_externals')) {
            return array_keys($uids);
        }

        $present = [];
        foreach (array_chunk(array_keys($uids), 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('user_uid')
                ->from('organization_externals')
                ->where($qb->expr()->in('user_uid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
                ->executeQuery();
            foreach ($result->fetchAll() as $row) {
                $present[(string) $row['user_uid']] = true;
            }
            $result->closeCursor();
        }

        return array_values(array_map('strval', array_keys(array_diff_key($uids, $present))));
    }

    /**
     * @param array<mixed> $grants
     * @param list<string> $userUids
     */
    private function countGrantsOf(array $grants, array $userUids): int
    {
        return count(array_filter($grants, static fn ($grant): bool => is_array($grant) && in_array((string) ($grant['user_uid'] ?? ''), $userUids, true)));
    }

    /**
     * @param array<string,mixed> $db
     * @return array<string,mixed>
     */
    private function restoreOrganizationRow(int $organizationId, array $db): array
    {
        $backupRow = $db['organization'] ?? null;
        if (!is_array($backupRow)) {
            throw new \RuntimeException('Archive is missing organization row');
        }
        if ((int) ($backupRow['id'] ?? 0) !== $organizationId) {
            throw new \RuntimeException('Archive organization id does not match target organization');
        }

        $existing = $this->fetchOneById('organizations', $organizationId);
        if ($existing === null) {
            throw new \RuntimeException('Target organization was not found');
        }

        $updatableColumns = array_values(array_diff(array_keys($existing), ['id']));
        $values = [];
        foreach ($updatableColumns as $column) {
            if (array_key_exists($column, $backupRow)) {
                $values[$column] = $backupRow[$column];
            }
        }
        if ($values !== []) {
            $this->updateById('organizations', $organizationId, $values);
        }

        return [
            'updatedColumns' => array_keys($values),
        ];
    }

    /**
     * @param array<string,mixed> $db
     * @return array<string,mixed>
     */
    private function restoreOrganizationMembers(int $organizationId, array $db): array
    {
        $members = is_array($db['organizationMembers'] ?? null) ? $db['organizationMembers'] : [];
        $delete = $this->db->getQueryBuilder();
        $delete->delete('organization_members')
            ->where($delete->expr()->eq('organization_id', $delete->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        $rows = [];
        foreach ($members as $member) {
            if (!is_array($member)) {
                continue;
            }
            $userUid = trim((string) ($member['user_uid'] ?? ''));
            if ($userUid === '') {
                continue;
            }
            $rows[] = [
                'organization_id' => $organizationId,
                'user_uid' => $userUid,
                'role' => (string) ($member['role'] ?? 'member'),
                'created_at' => $member['created_at'] ?? $this->utcNow(),
            ];
        }

        $inserted = $this->insertRows('organization_members', $rows);

        return [
            'replaced' => $inserted,
        ];
    }

    /**
     * @param array<string,mixed> $db
     * @return array<string,mixed>
     */
    private function restoreSubscriptions(int $organizationId, array $db): array
    {
        $subscriptions = is_array($db['subscriptions'] ?? null) ? $db['subscriptions'] : [];
        $history = is_array($db['subscriptionsHistory'] ?? null) ? $db['subscriptionsHistory'] : [];

        $current = $this->fetchAllWhereInt('subscriptions', 'organization_id', $organizationId);
        $currentIds = array_values(array_filter(array_map(static fn (array $row): int => isset($row['id']) ? (int) $row['id'] : 0, $current), static fn (int $id): bool => $id > 0));
        $restoredIds = array_values(array_filter(array_map(static fn (array $row): int => isset($row['id']) ? (int) $row['id'] : 0, $subscriptions), static fn (int $id): bool => $id > 0));
        $allIds = array_values(array_unique(array_merge($currentIds, $restoredIds)));

        if ($allIds !== []) {
            $this->deleteWhereInInt('subscriptions_history', 'subscription_id', $allIds);
        }

        $delete = $this->db->getQueryBuilder();
        $delete->delete('subscriptions')
            ->where($delete->expr()->eq('organization_id', $delete->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        $subRows = [];
        foreach ($subscriptions as $subscription) {
            if (!is_array($subscription)) {
                continue;
            }
            $subscription['organization_id'] = $organizationId;
            $subRows[] = $subscription;
        }
        $insertedSubscriptions = $this->insertRows('subscriptions', $subRows);
        $insertedHistory = $this->insertRows('subscriptions_history', $history);

        return [
            'subscriptions' => $insertedSubscriptions,
            'history' => $insertedHistory,
        ];
    }

    /**
     * @param array<string,mixed> $db
     * @return array<string,mixed>
     */
    private function restoreProjectCreatorData(int $organizationId, array $db): array
    {
        if (!$this->tableExists('custom_projects')) {
            return [
                'status' => 'skipped',
                'reason' => 'custom_projects table unavailable',
                'currentBoardIds' => [],
                'restoredBoardIds' => [],
                'restoredProjectRows' => [],
            ];
        }

        $customProjects = is_array($db['customProjects'] ?? null) ? $db['customProjects'] : [];
        $timeline = is_array($db['projectTimeline'] ?? null) ? $db['projectTimeline'] : [];
        $notesPublic = is_array($db['projectNotesPublic'] ?? null) ? $db['projectNotesPublic'] : [];
        $notesPrivate = is_array($db['projectNotesPrivate'] ?? null) ? $db['projectNotesPrivate'] : [];
        $activity = is_array($db['projectActivity'] ?? null) ? $db['projectActivity'] : [];
        $doneSync = is_array($db['projectDoneSync'] ?? null) ? $db['projectDoneSync'] : [];
        $fileProcessing = is_array($db['projectFileProcessing'] ?? null) ? $db['projectFileProcessing'] : [];
        $ocrDocTypes = is_array($db['projectOcrDocTypes'] ?? null) ? $db['projectOcrDocTypes'] : [];

        $currentProjects = $this->fetchAllWhereInt('custom_projects', 'organization_id', $organizationId);
        $currentProjectIds = array_values(array_filter(array_map(static fn (array $row): int => isset($row['id']) ? (int) $row['id'] : 0, $currentProjects), static fn (int $id): bool => $id > 0));
        $currentBoardIds = array_values(array_filter(array_map(static fn (array $row): int => isset($row['board_id']) ? (int) $row['board_id'] : 0, $currentProjects), static fn (int $id): bool => $id > 0));

        $restoredProjectRows = [];
        foreach ($customProjects as $project) {
            if (!is_array($project)) {
                continue;
            }
            $project['organization_id'] = $organizationId;
            $restoredProjectRows[] = $project;
        }

        $restoredProjectIds = array_values(array_filter(array_map(static fn (array $row): int => isset($row['id']) ? (int) $row['id'] : 0, $restoredProjectRows), static fn (int $id): bool => $id > 0));
        $restoredBoardIds = array_values(array_filter(array_map(static fn (array $row): int => isset($row['board_id']) ? (int) $row['board_id'] : 0, $restoredProjectRows), static fn (int $id): bool => $id > 0));
        $allProjectIds = array_values(array_unique(array_merge($currentProjectIds, $restoredProjectIds)));

        if ($allProjectIds !== []) {
            if ($this->tableExists('project_timeline_items')) {
                $this->deleteWhereInInt('project_timeline_items', 'project_id', $allProjectIds);
            }
            if ($this->tableExists('project_notes')) {
                $this->deleteWhereInInt('project_notes', 'project_id', $allProjectIds);
            }
            if ($this->tableExists('project_activity_events')) {
                $this->deleteWhereInInt('project_activity_events', 'project_id', $allProjectIds);
            }
            if ($this->tableExists('project_deck_done_sync')) {
                $this->deleteWhereInInt('project_deck_done_sync', 'project_id', $allProjectIds);
            }
        }

        if ($this->tableExists('project_file_processing')) {
            $this->deleteWhereInt('project_file_processing', 'organization_id', $organizationId);
        }
        if ($this->tableExists('project_ocr_doc_types')) {
            $this->deleteWhereInt('project_ocr_doc_types', 'organization_id', $organizationId);
        }

        $this->deleteWhereInt('custom_projects', 'organization_id', $organizationId);

        $notes = array_merge($notesPublic, $notesPrivate);
        $fileProcessingRows = $this->forceOrganizationId($fileProcessing, $organizationId);
        $ocrDocTypesRows = $this->forceOrganizationId($ocrDocTypes, $organizationId);

        $insertedProjects = $this->insertRows('custom_projects', $restoredProjectRows);
        $insertedTimeline = $this->tableExists('project_timeline_items') ? $this->insertRows('project_timeline_items', $timeline) : 0;
        $insertedNotes = $this->tableExists('project_notes') ? $this->insertRows('project_notes', $notes) : 0;
        $insertedActivity = $this->tableExists('project_activity_events') ? $this->insertRows('project_activity_events', $activity) : 0;
        $insertedDoneSync = $this->tableExists('project_deck_done_sync') ? $this->insertRows('project_deck_done_sync', $doneSync) : 0;
        $insertedFileProcessing = $this->tableExists('project_file_processing') ? $this->insertRows('project_file_processing', $fileProcessingRows) : 0;
        $insertedOcrDocTypes = $this->tableExists('project_ocr_doc_types') ? $this->insertRows('project_ocr_doc_types', $ocrDocTypesRows) : 0;

        return [
            'projects' => $insertedProjects,
            'timeline' => $insertedTimeline,
            'notes' => $insertedNotes,
            'activity' => $insertedActivity,
            'doneSync' => $insertedDoneSync,
            'fileProcessing' => $insertedFileProcessing,
            'ocrDocTypes' => $insertedOcrDocTypes,
            'currentBoardIds' => $currentBoardIds,
            'restoredBoardIds' => $restoredBoardIds,
            'restoredProjectRows' => $restoredProjectRows,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function forceOrganizationId(array $rows, int $organizationId): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['organization_id'] = $organizationId;
            $mapped[] = $row;
        }

        return $mapped;
    }

    /**
     * @param array<string,mixed> $db
     * @param array<string,mixed> $projectResult
     * @return array<string,mixed>
     */
    private function restoreDeckData(int $organizationId, array $db, array $projectResult): array
    {
        if (!$this->tableExists('deck_boards')) {
            return [
                'status' => 'skipped',
                'reason' => 'deck tables unavailable',
            ];
        }

        $deckBundles = is_array($db['deckBundles'] ?? null) ? $db['deckBundles'] : [];
        $currentBoardIds = is_array($projectResult['currentBoardIds'] ?? null) ? $projectResult['currentBoardIds'] : [];
        $restoredBoardIds = is_array($projectResult['restoredBoardIds'] ?? null) ? $projectResult['restoredBoardIds'] : [];
        foreach ($deckBundles as $bundle) {
            if (!is_array($bundle)) {
                continue;
            }
            $boardId = isset($bundle['boardId']) ? (int) $bundle['boardId'] : 0;
            if ($boardId > 0) {
                $restoredBoardIds[] = $boardId;
            } elseif (is_array($bundle['board'] ?? null)) {
                $boardId = isset($bundle['board']['id']) ? (int) $bundle['board']['id'] : 0;
                if ($boardId > 0) {
                    $restoredBoardIds[] = $boardId;
                }
            }
        }
        $targetBoardIds = array_values(array_unique(array_filter(array_merge($currentBoardIds, $restoredBoardIds), static fn (int $id): bool => $id > 0)));

        if ($targetBoardIds !== []) {
            $this->deleteDeckDataByBoardIds($targetBoardIds);
        }

        $insertedBoards = 0;
        $insertedStacks = 0;
        $insertedCards = 0;
        $insertedLabels = 0;
        $insertedBoardAcl = 0;
        $insertedAssignedUsers = 0;
        $insertedAssignedLabels = 0;
        $insertedAttachments = 0;

        foreach ($deckBundles as $bundle) {
            if (!is_array($bundle)) {
                continue;
            }

            $board = $bundle['board'] ?? null;
            if (is_array($board)) {
                $insertedBoards += $this->insertRows('deck_boards', [$board]);
            }
            $insertedStacks += $this->insertRows('deck_stacks', is_array($bundle['stacks'] ?? null) ? $bundle['stacks'] : []);
            $insertedCards += $this->insertRows('deck_cards', is_array($bundle['cards'] ?? null) ? $bundle['cards'] : []);
            $insertedLabels += $this->insertRows('deck_labels', is_array($bundle['labels'] ?? null) ? $bundle['labels'] : []);
            $insertedBoardAcl += $this->insertRows('deck_board_acl', is_array($bundle['boardAcl'] ?? null) ? $bundle['boardAcl'] : []);
            $insertedAssignedUsers += $this->insertRows('deck_assigned_users', is_array($bundle['assignedUsers'] ?? null) ? $bundle['assignedUsers'] : []);
            $insertedAssignedLabels += $this->insertRows('deck_assigned_labels', is_array($bundle['assignedLabels'] ?? null) ? $bundle['assignedLabels'] : []);
            $insertedAttachments += $this->insertRows('deck_attachment', is_array($bundle['attachments'] ?? null) ? $bundle['attachments'] : []);
        }

        return [
            'boards' => $insertedBoards,
            'stacks' => $insertedStacks,
            'cards' => $insertedCards,
            'labels' => $insertedLabels,
            'boardAcl' => $insertedBoardAcl,
            'assignedUsers' => $insertedAssignedUsers,
            'assignedLabels' => $insertedAssignedLabels,
            'attachments' => $insertedAttachments,
            'targetBoardCount' => count($targetBoardIds),
            'organizationId' => $organizationId,
        ];
    }

    /**
     * @param list<int> $boardIds
     */
    private function deleteDeckDataByBoardIds(array $boardIds): void
    {
        $boardIds = array_values(array_unique(array_filter($boardIds, static fn (int $id): bool => $id > 0)));
        if ($boardIds === []) {
            return;
        }

        $stackIds = $this->selectIdsWhereInInt('deck_stacks', 'id', 'board_id', $boardIds);
        $cardIds = $stackIds === [] ? [] : $this->selectIdsWhereInInt('deck_cards', 'id', 'stack_id', $stackIds);

        if ($cardIds !== []) {
            $this->deleteWhereInInt('deck_assigned_users', 'card_id', $cardIds);
            $this->deleteWhereInInt('deck_assigned_labels', 'card_id', $cardIds);
            $this->deleteWhereInInt('deck_attachment', 'card_id', $cardIds);
        }

        if ($stackIds !== []) {
            $this->deleteWhereInInt('deck_cards', 'stack_id', $stackIds);
        }

        $this->deleteWhereInInt('deck_stacks', 'board_id', $boardIds);
        $this->deleteWhereInInt('deck_labels', 'board_id', $boardIds);
        $this->deleteWhereInInt('deck_board_acl', 'board_id', $boardIds);
        $this->deleteWhereInInt('deck_boards', 'id', $boardIds);
    }

    /**
     * @param array<string,mixed> $archive
     * @return array<string,mixed>
     */
    private function applyFileRestore(int $organizationId, string $zipPath, array $archive): array
    {
        $db = is_array($archive['db'] ?? null) ? $archive['db'] : [];
        $customProjects = is_array($db['customProjects'] ?? null) ? $db['customProjects'] : [];
        $filesByProject = is_array($archive['filesByProject'] ?? null) ? $archive['filesByProject'] : [];

        $projectRowsById = [];
        foreach ($customProjects as $project) {
            if (!is_array($project)) {
                continue;
            }
            $projectId = isset($project['id']) ? (int) $project['id'] : 0;
            if ($projectId > 0) {
                $projectRowsById[$projectId] = $project;
            }
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new \RuntimeException('Failed to reopen backup archive for file restore');
        }

        $projectsRestored = 0;
        $filesRestored = 0;
        try {
            foreach ($filesByProject as $projectIdRaw => $entries) {
                $projectId = (int) $projectIdRaw;
                if ($projectId <= 0 || !isset($projectRowsById[$projectId])) {
                    throw new \RuntimeException(sprintf('Archive file payload references unknown project %d', $projectId));
                }

                $project = $projectRowsById[$projectId];
                $folder = $this->resolveProjectSharedFolder($project, true);
                if ($folder === null) {
                    throw new \RuntimeException(sprintf('Shared folder is not resolvable for project %d', $projectId));
                }
                $this->syncRestoredProjectFolderMetadata($projectId, $project, $folder);

                $this->clearFolderContents($folder);
                foreach ($entries as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $zipEntryPath = (string) ($entry['zipPath'] ?? '');
                    $relativePath = (string) ($entry['relativePath'] ?? '');
                    if ($zipEntryPath === '' || $relativePath === '') {
                        continue;
                    }

                    $this->writeZipEntryToFolder($zip, $folder, $zipEntryPath, $relativePath);
                    $filesRestored++;
                }
                $projectsRestored++;
            }
        } finally {
            $zip->close();
        }

        return [
            'projectsRestored' => $projectsRestored,
            'filesRestored' => $filesRestored,
            'organizationId' => $organizationId,
        ];
    }

    private function clearFolderContents(\OCP\Files\Folder $folder): void
    {
        foreach ($folder->getDirectoryListing() as $node) {
            $node->delete();
        }
    }

    private function writeZipEntryToFolder(\ZipArchive $zip, \OCP\Files\Folder $baseFolder, string $zipEntryPath, string $relativePath): void
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '') {
            return;
        }

        $segments = array_values(array_filter(explode('/', $relativePath), static fn (string $part): bool => $part !== ''));
        if ($segments === []) {
            return;
        }

        $fileName = (string) array_pop($segments);
        $folder = $baseFolder;
        foreach ($segments as $segment) {
            $folder = $this->ensureChildFolder($folder, $segment);
        }

        try {
            $existing = $folder->get($fileName);
            $existing->delete();
        } catch (\Throwable) {
        }

        $input = $zip->getStream($zipEntryPath);
        if ($input === false) {
            throw new \RuntimeException(sprintf('Failed to read zip entry "%s"', $zipEntryPath));
        }

        $file = $folder->newFile($fileName);
        $output = $file->fopen('wb');
        if ($output === false) {
            fclose($input);
            throw new \RuntimeException(sprintf('Failed to open output file "%s"', $relativePath));
        }

        try {
            if (stream_copy_to_stream($input, $output) === false) {
                throw new \RuntimeException(sprintf('Failed to write output file "%s"', $relativePath));
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function ensureChildFolder(\OCP\Files\Folder $parent, string $name): \OCP\Files\Folder
    {
        $name = trim($name);
        if ($name === '') {
            return $parent;
        }

        try {
            $node = $parent->get($name);
            if ($node instanceof \OCP\Files\Folder) {
                return $node;
            }
            $node->delete();
        } catch (\Throwable) {
        }

        return $parent->newFolder($name);
    }

    /**
     * @param array<string,mixed> $project
     */
    private function resolveProjectSharedFolder(array $project, bool $createIfMissing = false): ?\OCP\Files\Folder
    {
        $folderId = isset($project['folder_id']) ? (int) $project['folder_id'] : 0;
        if ($folderId > 0) {
            $nodes = $this->rootFolder->getById($folderId);
            foreach ($nodes as $node) {
                if ($node instanceof \OCP\Files\Folder) {
                    return $node;
                }
            }
        }

        $ownerId = trim((string) ($project['owner_id'] ?? ''));
        $folderPath = $this->determineProjectRestoreFolderPath($project);
        if ($ownerId === '' || $folderPath === '') {
            return null;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($ownerId);
            $node = $userFolder->get($folderPath);
            if ($node instanceof \OCP\Files\Folder) {
                return $node;
            }
        } catch (\Throwable) {
            if (!$createIfMissing) {
                return null;
            }
        }

        if (!$createIfMissing) {
            return null;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($ownerId);
            return $this->ensureRelativeFolder($userFolder, $folderPath);
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * @param array<string,mixed> $project
     */
    private function canProvisionProjectSharedFolder(array $project): bool
    {
        $ownerId = trim((string) ($project['owner_id'] ?? ''));
        $folderPath = $this->determineProjectRestoreFolderPath($project);
        if ($ownerId === '' || $folderPath === '') {
            return false;
        }

        try {
            $this->rootFolder->getUserFolder($ownerId);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $project
     */
    private function determineProjectRestoreFolderPath(array $project): string
    {
        $folderPath = trim((string) ($project['folder_path'] ?? ''), '/');
        if ($folderPath !== '') {
            return $folderPath;
        }

        return trim((string) ($project['name'] ?? ''));
    }

    private function ensureRelativeFolder(\OCP\Files\Folder $baseFolder, string $relativePath): \OCP\Files\Folder
    {
        $segments = array_values(array_filter(
            array_map(
                static fn (string $segment): string => trim(str_replace("\0", '', $segment)),
                explode('/', trim($relativePath, '/')),
            ),
            static fn (string $segment): bool => $segment !== '',
        ));

        $folder = $baseFolder;
        foreach ($segments as $segment) {
            $folder = $this->ensureChildFolder($folder, $segment);
        }

        return $folder;
    }

    /**
     * @param array<string,mixed> $project
     */
    private function syncRestoredProjectFolderMetadata(int $projectId, array $project, \OCP\Files\Folder $folder): void
    {
        $values = [
            'folder_id' => (int) $folder->getId(),
        ];

        $folderPath = $this->determineProjectRestoreFolderPath($project);
        if ($folderPath !== '') {
            $values['folder_path'] = $folderPath;
        }

        $this->updateById('custom_projects', $projectId, $values);
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function insertRows(string $table, array $rows): int
    {
        $inserted = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }

            $insert = $this->db->getQueryBuilder();
            $insert->insert($table);

            $values = [];
            foreach ($row as $column => $value) {
                if (!is_string($column) || trim($column) === '') {
                    continue;
                }
                $values[$column] = $insert->createNamedParameter($value, $this->inferParameterType($value));
            }
            if ($values === []) {
                continue;
            }

            $insert->values($values)->executeStatement();
            $inserted++;
        }

        return $inserted;
    }

    /**
     * @param list<int> $values
     */
    private function deleteWhereInInt(string $table, string $column, array $values): void
    {
        $values = array_values(array_unique(array_filter($values, static fn (int $id): bool => $id > 0)));
        if ($values === []) {
            return;
        }

        foreach (array_chunk($values, 500) as $chunk) {
            $delete = $this->db->getQueryBuilder();
            $delete->delete($table)
                ->where($delete->expr()->in($column, $delete->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeStatement();
        }
    }

    private function deleteWhereInt(string $table, string $column, int $value): void
    {
        $delete = $this->db->getQueryBuilder();
        $delete->delete($table)
            ->where($delete->expr()->eq($column, $delete->createNamedParameter($value, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * @return list<int>
     */
    private function selectIdsWhereInInt(string $table, string $selectColumn, string $whereColumn, array $whereValues): array
    {
        $whereValues = array_map(static fn ($id): int => (int) $id, $whereValues);
        $whereValues = array_values(array_unique(array_filter($whereValues, static fn (int $id): bool => $id > 0)));
        if ($whereValues === []) {
            return [];
        }

        $ids = [];
        foreach (array_chunk($whereValues, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select($selectColumn)
                ->from($table)
                ->where($qb->expr()->in($whereColumn, $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeQuery();

            while (($row = $result->fetch()) !== false) {
                $id = isset($row[$selectColumn]) ? (int) $row[$selectColumn] : 0;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            $result->closeCursor();
        }

        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchOneById(string $table, int $id): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from($table)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();

        $row = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $row;
    }

    private function updateById(string $table, int $id, array $values): void
    {
        if ($values === []) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update($table)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        foreach ($values as $column => $value) {
            if (!is_string($column) || trim($column) === '') {
                continue;
            }
            $qb->set($column, $qb->createNamedParameter($value, $this->inferParameterType($value)));
        }

        $qb->executeStatement();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchAllWhereInt(string $table, string $column, int $value): array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from($table)
            ->where($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)))
            ->executeQuery();

        $rows = $result->fetchAll();
        $result->closeCursor();

        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getJobRowById(int $jobId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();

        $row = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getJobRowByIdAndOrganization(int $jobId, int $organizationId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();

        $row = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $values
     */
    private function updateJob(int $jobId, array $values): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::JOBS_TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)));

        foreach ($values as $column => $value) {
            if (!is_string($column) || trim($column) === '') {
                continue;
            }
            $qb->set($column, $qb->createNamedParameter($value, $this->inferParameterType($value)));
        }

        $qb->executeStatement();
    }

    /**
     * @param array<string,mixed>|null $payload
     */
    private function insertEvent(int $jobId, string $level, string $message, ?array $payload = null, ?string $stepKey = null): void
    {
        $now = $this->utcNow();
        $sequenceNo = $this->nextEventSequenceNo($jobId);

        $insert = $this->db->getQueryBuilder();
        $insert->insert(self::EVENTS_TABLE)
            ->values([
                'job_id' => $insert->createNamedParameter($jobId, IQueryBuilder::PARAM_INT),
                'sequence_no' => $insert->createNamedParameter($sequenceNo, IQueryBuilder::PARAM_INT),
                'step_key' => $insert->createNamedParameter($stepKey),
                'level' => $insert->createNamedParameter($level, IQueryBuilder::PARAM_STR),
                'message' => $insert->createNamedParameter($message, IQueryBuilder::PARAM_STR),
                'payload_json' => $insert->createNamedParameter($payload !== null ? json_encode($payload, JSON_THROW_ON_ERROR) : null),
                'created_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_STR),
            ])
            ->executeStatement();
    }

    private function nextEventSequenceNo(int $jobId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('MAX(sequence_no)'), 'max_seq')
            ->from(self::EVENTS_TABLE)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $max = $result->fetchOne();
        $result->closeCursor();

        return ((int) $max) + 1;
    }

    /**
     * @param array<string,mixed> $values
     */
    private function upsertStep(int $jobId, string $stepKey, array $values): void
    {
        $existing = $this->getStepRow($jobId, $stepKey);
        $qb = $this->db->getQueryBuilder();

        if ($existing === null) {
            $qb->insert(self::STEPS_TABLE)
                ->values([
                    'job_id' => $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT),
                    'step_key' => $qb->createNamedParameter($stepKey, IQueryBuilder::PARAM_STR),
                    'status' => $qb->createNamedParameter($values['status'] ?? 'queued', IQueryBuilder::PARAM_STR),
                    'attempt' => $qb->createNamedParameter((int) ($values['attempt'] ?? 1), IQueryBuilder::PARAM_INT),
                    'retriable' => $qb->createNamedParameter((int) ((bool) ($values['retriable'] ?? true)), IQueryBuilder::PARAM_INT),
                    'result_json' => $qb->createNamedParameter($values['result_json'] ?? null),
                    'error_message' => $qb->createNamedParameter($values['error_message'] ?? null),
                    'started_at' => $qb->createNamedParameter($values['started_at'] ?? null),
                    'finished_at' => $qb->createNamedParameter($values['finished_at'] ?? null),
                    'updated_at' => $qb->createNamedParameter($values['updated_at'] ?? $this->utcNow()),
                ])
                ->executeStatement();
            return;
        }

        $qb->update(self::STEPS_TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter((int) $existing['id'], IQueryBuilder::PARAM_INT)));

        foreach ($values as $column => $value) {
            if ($column === 'result_json' && is_array($value)) {
                $value = json_encode($value, JSON_THROW_ON_ERROR);
            }
            if ($column === 'attempt') {
                $qb->set($column, $qb->createNamedParameter((int) $value, IQueryBuilder::PARAM_INT));
                continue;
            }
            if ($column === 'retriable') {
                $qb->set($column, $qb->createNamedParameter((int) ((bool) $value), IQueryBuilder::PARAM_INT));
                continue;
            }

            $qb->set($column, $qb->createNamedParameter($value, $this->inferParameterType($value)));
        }

        $qb->executeStatement();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getStepRow(int $jobId, string $stepKey): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::STEPS_TABLE)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('step_key', $qb->createNamedParameter($stepKey, IQueryBuilder::PARAM_STR)))
            ->setMaxResults(1)
            ->executeQuery();

        $row = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $row;
    }

    private function markStepRunning(int $jobId, string $stepKey): void
    {
        $now = $this->utcNow();
        $this->upsertStep($jobId, $stepKey, [
            'status' => 'running',
            'error_message' => null,
            'started_at' => $now,
            'finished_at' => null,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string,mixed>|null $result
     */
    private function markStepCompleted(int $jobId, string $stepKey, ?array $result = null): void
    {
        $now = $this->utcNow();
        $this->upsertStep($jobId, $stepKey, [
            'status' => 'completed',
            'retriable' => false,
            'result_json' => $result !== null ? json_encode($result, JSON_THROW_ON_ERROR) : null,
            'error_message' => null,
            'finished_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string,mixed>|null $result
     */
    private function markStepSkipped(int $jobId, string $stepKey, ?array $result = null): void
    {
        $now = $this->utcNow();
        $this->upsertStep($jobId, $stepKey, [
            'status' => 'skipped',
            'retriable' => false,
            'result_json' => $result !== null ? json_encode($result, JSON_THROW_ON_ERROR) : null,
            'error_message' => null,
            'started_at' => null,
            'finished_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function utcNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /**
     * @param mixed $value
     */
    private function decodeJsonNullable($value)
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param mixed $value
     */
    private function inferParameterType($value): ?int
    {
        if (is_int($value)) {
            return IQueryBuilder::PARAM_INT;
        }
        if (is_bool($value)) {
            return IQueryBuilder::PARAM_BOOL;
        }
        if (is_string($value)) {
            return IQueryBuilder::PARAM_STR;
        }
        return null;
    }
}
