<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCP\IDBConnection;
use OCP\IUserManager;

use OCA\Organization\Db\OrganizationMapper;
use OCA\Organization\Db\UserMapper;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

class AccountHandoverService
{
    private const JOBS_TABLE = 'org_aho_jobs';
    private const EVENTS_TABLE = 'org_aho_events';
    private const STEPS_TABLE = 'org_aho_steps';

    /** @var list<string> */
    private const STEP_ORDER = ['projectcreator', 'deck', 'finalize'];

    public function __construct(
        private IDBConnection $db,
        private IUserManager $userManager,
        private OrganizationMapper $organizationMapper,
        private UserMapper $userMapper,
        private NotificationService $notificationService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function createAndRun(
        int $organizationId,
        string $sourceUserId,
        string $targetUserId,
        string $requestedByUserId,
        bool $dryRun = false,
        bool $removeSourceFromGroups = false,
        bool $remapDeckContent = true,
        ?string $idempotencyKey = null,
    ): array {
        $this->validateInput($organizationId, $sourceUserId, $targetUserId);

        $requestFingerprint = $this->buildRequestFingerprint(
            $organizationId,
            $sourceUserId,
            $targetUserId,
            $dryRun,
            $removeSourceFromGroups,
            $remapDeckContent,
        );

        $normalizedIdempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        if ($normalizedIdempotencyKey !== null) {
            $existing = $this->findJobByIdempotencyKey($organizationId, $normalizedIdempotencyKey);
            if ($existing !== null) {
                $existingFingerprint = (string) ($existing['request_fingerprint'] ?? '');
                if ($existingFingerprint !== '' && $existingFingerprint !== $requestFingerprint) {
                    throw new \InvalidArgumentException('Idempotency key already used with different parameters');
                }

                return $this->mapJobRow($existing, true);
            }
        }

        $now = $this->utcNow();
        try {
            $jobId = $this->insertJob([
                'organization_id' => $organizationId,
                'source_user_uid' => $sourceUserId,
                'target_user_uid' => $targetUserId,
                'requested_by_uid' => $requestedByUserId,
                'status' => 'queued',
                'dry_run' => $dryRun,
                'remove_source_from_groups' => $removeSourceFromGroups,
                'remap_deck_content' => $remapDeckContent,
                'idempotency_key' => $normalizedIdempotencyKey,
                'request_fingerprint' => $requestFingerprint,
                'attempt' => 1,
                'result_json' => null,
                'error_message' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'started_at' => null,
                'finished_at' => null,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if ($normalizedIdempotencyKey === null) {
                throw $e;
            }

            $existing = $this->findJobByIdempotencyKey($organizationId, $normalizedIdempotencyKey);
            if ($existing !== null) {
                return $this->mapJobRow($existing, true);
            }

            throw $e;
        }

        $this->insertEvent($jobId, 'info', 'Account handover job queued', [
            'organizationId' => $organizationId,
            'sourceUserId' => $sourceUserId,
            'targetUserId' => $targetUserId,
            'requestedByUserId' => $requestedByUserId,
            'dryRun' => $dryRun,
            'requestFingerprint' => $requestFingerprint,
        ]);

        if (!$dryRun) {
            $this->notifyHandoverStarted(
                $organizationId,
                $sourceUserId,
                $targetUserId,
                $requestedByUserId,
            );
        }

        if ($dryRun) {
            return $this->runJob($jobId);
        }

        $jobRow = $this->getJobRowById($jobId);
        if ($jobRow === null) {
            throw new \RuntimeException('Failed to load created job');
        }

        return $this->mapJobRow($jobRow, true);
    }

    /**
     * @return array<string,mixed>
     */
    public function runJob(int $jobId): array
    {
        $job = $this->getJobRowById($jobId);
        if ($job === null) {
            throw new \InvalidArgumentException('Job not found');
        }

        if ($job['status'] === 'completed') {
            return $this->mapJobRow($job, true);
        }

        $now = $this->utcNow();
        $this->updateJob($jobId, [
            'status' => 'running',
            'updated_at' => $now,
            'started_at' => $job['started_at'] ?? $now,
            'finished_at' => null,
            'error_message' => null,
        ]);
        $this->insertEvent($jobId, 'info', 'Account handover execution started');

        $latestJob = $this->getJobRowById($jobId);
        if ($latestJob === null) {
            throw new \RuntimeException('Job disappeared during execution');
        }

        $stepResults = [];

        try {
            foreach (self::STEP_ORDER as $stepKey) {
                $stepResults[$stepKey] = $this->executeStep($latestJob, $stepKey, $stepResults);
            }

            $result = [
                'projectCreator' => $stepResults['projectcreator'] ?? null,
                'deck' => $stepResults['deck'] ?? null,
                'finalize' => $stepResults['finalize'] ?? null,
            ];

            $this->updateJob($jobId, [
                'status' => 'completed',
                'result_json' => json_encode($result, JSON_THROW_ON_ERROR),
                'error_message' => null,
                'finished_at' => $this->utcNow(),
                'updated_at' => $this->utcNow(),
            ]);
            $this->insertEvent($jobId, 'info', 'Account handover completed', $result);
            $this->notifyHandoverCompleted($latestJob);

            $jobRow = $this->getJobRowById($jobId);
            if ($jobRow === null) {
                throw new \RuntimeException('Failed to load completed job');
            }

            return $this->mapJobRow($jobRow, true);
        } catch (\Throwable $e) {
            $this->updateJob($jobId, [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => $this->utcNow(),
                'updated_at' => $this->utcNow(),
            ]);
            $this->insertEvent($jobId, 'error', 'Account handover failed', [
                'error' => $e->getMessage(),
            ]);
            $this->notifyHandoverFailed($latestJob);

            $this->logger->error('Organization account handover failed', [
                'exception' => $e,
                'jobId' => $jobId,
            ]);

            $jobRow = $this->getJobRowById($jobId);
            if ($jobRow === null) {
                throw new \RuntimeException('Failed to load failed job');
            }

            return $this->mapJobRow($jobRow, true);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function retryJob(int $jobId, bool $failedStepsOnly = true): array
    {
        $job = $this->getJobRowById($jobId);
        if ($job === null) {
            throw new \InvalidArgumentException('Job not found');
        }

        $now = $this->utcNow();
        $steps = $this->listStepRows($jobId);
        foreach (self::STEP_ORDER as $stepKey) {
            $step = $steps[$stepKey] ?? null;
            $shouldReset = $step === null || !$failedStepsOnly || (($step['status'] ?? '') === 'failed');
            if (!$shouldReset) {
                continue;
            }

            $this->upsertStep($jobId, $stepKey, [
                'status' => 'queued',
                'retriable' => true,
                'error_message' => null,
                'result_json' => null,
                'started_at' => null,
                'finished_at' => null,
                'updated_at' => $now,
            ]);
        }

        $this->updateJob($jobId, [
            'status' => 'queued',
            'attempt' => ((int) $job['attempt']) + 1,
            'error_message' => null,
            'result_json' => null,
            'started_at' => null,
            'finished_at' => null,
            'updated_at' => $now,
        ]);

        $this->insertEvent($jobId, 'info', 'Account handover requeued', [
            'failedStepsOnly' => $failedStepsOnly,
        ]);

        $updated = $this->getJobRowById($jobId);
        if ($updated === null) {
            throw new \RuntimeException('Failed to load requeued job');
        }

        return $this->mapJobRow($updated, true);
    }

    /**
     * @return array<string,mixed>
     */
    public function getJob(int $organizationId, int $jobId): array
    {
        $row = $this->getJobRowByIdAndOrganization($jobId, $organizationId);
        if ($row === null) {
            throw new \InvalidArgumentException('Job not found');
        }

        return $this->mapJobRow($row, true);
    }

    /**
     * @return array<string,mixed>
     */
    public function listJobs(int $organizationId, ?string $status, int $limit, int $offset): array
    {
        $effectiveLimit = max(1, min($limit, 100));
        $effectiveOffset = max(0, $offset);
        $effectiveStatus = $status === null ? '' : trim($status);

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)));

        if ($effectiveStatus !== '') {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($effectiveStatus)));
        }

        $result = $qb->orderBy('id', 'DESC')
            ->setMaxResults($effectiveLimit)
            ->setFirstResult($effectiveOffset)
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
    public function listEvents(int $organizationId, int $jobId, int $limit, int $offset): array
    {
        if ($this->getJobRowByIdAndOrganization($jobId, $organizationId) === null) {
            throw new \InvalidArgumentException('Job not found');
        }

        $effectiveLimit = max(1, min($limit, 200));
        $effectiveOffset = max(0, $offset);

        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::EVENTS_TABLE)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, \PDO::PARAM_INT)))
            ->orderBy('sequence_no', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($effectiveLimit)
            ->setFirstResult($effectiveOffset)
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

    public function getOldestQueuedJobId(): ?int
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('id')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('queued')))
            ->orderBy('id', 'ASC')
            ->setMaxResults(1)
            ->executeQuery();

        $idValue = $result->fetchOne();
        $result->closeCursor();

        return $idValue === false ? null : (int) $idValue;
    }

    private function validateInput(int $organizationId, string $sourceUserId, string $targetUserId): void
    {
        if ($sourceUserId === $targetUserId) {
            throw new \InvalidArgumentException('Source and target users must be different');
        }

        if ($this->userManager->get($sourceUserId) === null) {
            throw new \InvalidArgumentException('Source user does not exist');
        }

        if ($this->userManager->get($targetUserId) === null) {
            throw new \InvalidArgumentException('Target user does not exist');
        }

        $sourceMembership = $this->userMapper->getOrganizationMembership($sourceUserId);
        $targetMembership = $this->userMapper->getOrganizationMembership($targetUserId);

        if ($sourceMembership === null || (int) $sourceMembership['organization_id'] !== $organizationId) {
            throw new \InvalidArgumentException('Source user is not a member of the organization');
        }

        if ($targetMembership === null || (int) $targetMembership['organization_id'] !== $organizationId) {
            throw new \InvalidArgumentException('Target user is not a member of the organization');
        }
    }

    private function notifyHandoverStarted(
        int $organizationId,
        string $sourceUserId,
        string $targetUserId,
        string $requestedByUserId,
    ): void {
        $organizationName = $this->getOrganizationName($organizationId);
        $this->notificationService->notifyOrganizationHandoverStarted(
            $organizationId,
            $organizationName,
            $sourceUserId,
            $this->getUserDisplayName($sourceUserId),
            $targetUserId,
            $this->getUserDisplayName($targetUserId),
            $requestedByUserId,
        );
    }

    /**
     * @param array<string,mixed> $job
     */
    private function notifyHandoverCompleted(array $job): void
    {
        $organizationId = (int) $job['organization_id'];
        $sourceUserId = (string) $job['source_user_uid'];
        $targetUserId = (string) $job['target_user_uid'];
        $requestedByUserId = isset($job['requested_by_uid']) ? (string) $job['requested_by_uid'] : null;

        $this->notificationService->notifyOrganizationHandoverCompleted(
            $organizationId,
            $this->getOrganizationName($organizationId),
            $sourceUserId,
            $this->getUserDisplayName($sourceUserId),
            $targetUserId,
            $this->getUserDisplayName($targetUserId),
            $requestedByUserId,
        );
    }

    /**
     * @param array<string,mixed> $job
     */
    private function notifyHandoverFailed(array $job): void
    {
        $organizationId = (int) $job['organization_id'];
        $sourceUserId = (string) $job['source_user_uid'];
        $targetUserId = (string) $job['target_user_uid'];
        $requestedByUserId = isset($job['requested_by_uid']) ? (string) $job['requested_by_uid'] : null;

        $this->notificationService->notifyOrganizationHandoverFailed(
            $organizationId,
            $this->getOrganizationName($organizationId),
            $sourceUserId,
            $this->getUserDisplayName($sourceUserId),
            $targetUserId,
            $this->getUserDisplayName($targetUserId),
            $requestedByUserId,
        );
    }

    private function getOrganizationName(int $organizationId): string
    {
        $organization = $this->organizationMapper->find($organizationId);
        return $organization?->getName() ?? sprintf('Organization #%d', $organizationId);
    }

    private function getUserDisplayName(string $userId): string
    {
        return $this->userManager->get($userId)?->getDisplayName() ?? $userId;
    }

    /**
     * @return array<string,mixed>
     */
    private function executeStep(array $job, string $stepKey, array $stepResults): array
    {
        $jobId = (int) $job['id'];
        $existingStep = $this->getStepRow($jobId, $stepKey);
        if ($existingStep !== null && in_array((string) $existingStep['status'], ['completed', 'skipped'], true)) {
            $existingResult = $this->decodeJsonNullable($existingStep['result_json']);
            return is_array($existingResult) ? $existingResult : ['result' => $existingResult];
        }

        $attempt = ((int) ($existingStep['attempt'] ?? 0)) + 1;
        $now = $this->utcNow();

        $this->upsertStep($jobId, $stepKey, [
            'status' => 'running',
            'attempt' => $attempt,
            'retriable' => true,
            'error_message' => null,
            'started_at' => $now,
            'finished_at' => null,
            'updated_at' => $now,
        ]);
        $this->insertEvent($jobId, 'info', 'Step started', [
            'stepKey' => $stepKey,
            'attempt' => $attempt,
        ], $stepKey);

        try {
            $result = match ($stepKey) {
                'projectcreator' => $this->runProjectCreatorStep($job),
                'deck' => $this->runDeckStep($job),
                'finalize' => $this->runFinalizeStep($job, $stepResults),
                default => throw new \RuntimeException(sprintf('Unsupported step %s', $stepKey)),
            };

            $completedAt = $this->utcNow();
            $stepStatus = $this->inferStepStatusFromResult($result);
            $this->upsertStep($jobId, $stepKey, [
                'status' => $stepStatus,
                'attempt' => $attempt,
                'retriable' => false,
                'result_json' => json_encode($result, JSON_THROW_ON_ERROR),
                'error_message' => null,
                'finished_at' => $completedAt,
                'updated_at' => $completedAt,
            ]);
            $this->insertEvent($jobId, 'info', $stepStatus === 'skipped' ? 'Step skipped' : 'Step completed', [
                'stepKey' => $stepKey,
                'status' => $stepStatus,
            ], $stepKey);

            return $result;
        } catch (\Throwable $e) {
            $failedAt = $this->utcNow();
            $this->upsertStep($jobId, $stepKey, [
                'status' => 'failed',
                'attempt' => $attempt,
                'retriable' => true,
                'error_message' => $e->getMessage(),
                'finished_at' => $failedAt,
                'updated_at' => $failedAt,
            ]);
            $this->insertEvent($jobId, 'error', 'Step failed', [
                'stepKey' => $stepKey,
                'error' => $e->getMessage(),
            ], $stepKey);

            throw $e;
        }
    }

    /**
     * @param mixed $result
     */
    private function inferStepStatusFromResult($result): string
    {
        if (is_array($result) && isset($result['status']) && is_string($result['status'])) {
            $normalized = strtolower(trim($result['status']));
            if ($normalized === 'skipped') {
                return 'skipped';
            }
        }

        return 'completed';
    }

    /**
     * @return array<string,mixed>|array{status:string,warning:string}
     */
    private function runProjectCreatorStep(array $job): array
    {
        if ($this->toBool($job['dry_run'])) {
            return [
                'status' => 'skipped',
                'warning' => 'Dry run mode - projectcreator step skipped',
            ];
        }

        return $this->runOptionalHandoverService(
            (int) $job['id'],
            '\\OCA\\ProjectCreatorAIO\\Service\\ProjectHandoverService',
            (int) $job['organization_id'],
            (string) $job['source_user_uid'],
            (string) $job['target_user_uid'],
            $this->toBool($job['remove_source_from_groups']),
            $this->toBool($job['remap_deck_content']),
        );
    }

    /**
     * @return array<string,mixed>|array{status:string,warning:string}
     */
    private function runDeckStep(array $job): array
    {
        if ($this->toBool($job['dry_run'])) {
            return [
                'status' => 'skipped',
                'warning' => 'Dry run mode - deck step skipped',
            ];
        }

        return $this->runOptionalHandoverService(
            (int) $job['id'],
            '\\OCA\\Deck\\Service\\AccountHandoverService',
            (int) $job['organization_id'],
            (string) $job['source_user_uid'],
            (string) $job['target_user_uid'],
            $this->toBool($job['remove_source_from_groups']),
            $this->toBool($job['remap_deck_content']),
        );
    }

    /**
     * @param array<string,mixed> $stepResults
     * @return array<string,mixed>
     */
    private function runFinalizeStep(array $job, array $stepResults): array
    {
        $summary = [
            'status' => 'ok',
            'dryRun' => $this->toBool($job['dry_run']),
            'completedSteps' => array_keys($stepResults),
        ];

        if ($this->toBool($job['dry_run'])) {
            $summary['dryRunPreview'] = $this->buildDryRunResult(
                (int) $job['id'],
                (int) $job['organization_id'],
                (string) $job['source_user_uid'],
                $this->toBool($job['remove_source_from_groups']),
                $this->toBool($job['remap_deck_content']),
            );
        }

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildDryRunResult(int $jobId, int $organizationId, string $sourceUserId, bool $removeSourceFromGroups, bool $remapDeckContent): array
    {
        $warnings = [];

        [$projectCount, $projectWarning] = $this->countOwnedProjects($organizationId, $sourceUserId);
        if ($projectWarning !== null) {
            $warnings[] = $projectWarning;
            $this->insertEvent($jobId, 'warning', $projectWarning);
        }

        [$deckBoardCount, $deckWarning] = $this->countOwnedDeckBoards($organizationId, $sourceUserId);
        if ($deckWarning !== null) {
            $warnings[] = $deckWarning;
            $this->insertEvent($jobId, 'warning', $deckWarning);
        }

        $result = [
            'mode' => 'dry-run',
            'organizationId' => $organizationId,
            'projectsOwnedBySource' => $projectCount,
            'deckBoardsOwnedBySource' => $deckBoardCount,
            'removeSourceFromGroups' => $removeSourceFromGroups,
            'remapDeckContent' => $remapDeckContent,
        ];

        if ($warnings !== []) {
            $result['warnings'] = $warnings;
        }

        return $result;
    }

    /**
     * @return array{0:int,1:?string}
     */
    private function countOwnedProjects(int $organizationId, string $sourceUserId): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*)'))
                ->from('custom_projects')
                ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
                ->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($sourceUserId)));

            return [(int) $qb->executeQuery()->fetchOne(), null];
        } catch (\Throwable $e) {
            $this->logger->debug('Account handover dry-run project count failed', [
                'exception' => $e,
                'organizationId' => $organizationId,
            ]);

            return [0, 'Project ownership count is unavailable on this instance'];
        }
    }

    /**
     * @return array{0:int,1:?string}
     */
    private function countOwnedDeckBoards(int $organizationId, string $sourceUserId): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(DISTINCT b.id)'))
                ->from('custom_projects', 'cp')
                ->innerJoin('cp', 'deck_boards', 'b', $qb->expr()->eq('cp.board_id', 'b.id'))
                ->where($qb->expr()->eq('cp.organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
                ->andWhere($qb->expr()->eq('b.owner', $qb->createNamedParameter($sourceUserId)));

            return [(int) $qb->executeQuery()->fetchOne(), null];
        } catch (\Throwable $e) {
            $this->logger->debug('Account handover dry-run deck count failed', [
                'exception' => $e,
                'organizationId' => $organizationId,
            ]);

            return [0, 'Deck ownership count is unavailable on this instance'];
        }
    }

    /**
     * @return array<string,mixed>|array{status:string,warning:string}
     */
    private function runOptionalHandoverService(
        int $jobId,
        string $serviceClass,
        int $organizationId,
        string $sourceUserId,
        string $targetUserId,
        bool $removeSourceFromGroups,
        bool $remapDeckContent,
    ): array {
        if (!class_exists($serviceClass)) {
            $warning = sprintf('%s is not available, skipping', $serviceClass);
            $this->insertEvent($jobId, 'warning', $warning, null, null);

            return [
                'status' => 'skipped',
                'warning' => $warning,
            ];
        }

        try {
            $service = \OC::$server->get($serviceClass);
        } catch (\Throwable $e) {
            $warning = sprintf('%s is not registered, skipping', $serviceClass);
            $this->insertEvent($jobId, 'warning', $warning, [
                'error' => $e->getMessage(),
                'serviceClass' => $serviceClass,
            ]);

            return [
                'status' => 'skipped',
                'warning' => $warning,
            ];
        }

        if ($serviceClass === '\\OCA\\ProjectCreatorAIO\\Service\\ProjectHandoverService') {
            if (!method_exists($service, 'handoverUserInOrganization')) {
                throw new \RuntimeException(sprintf('%s::handoverUserInOrganization is not available', $serviceClass));
            }
            $result = $service->handoverUserInOrganization($sourceUserId, $targetUserId, $organizationId, $removeSourceFromGroups, $remapDeckContent);
        } elseif ($serviceClass === '\\OCA\\Deck\\Service\\AccountHandoverService') {
            if (!method_exists($service, 'handoverUserInOrganization')) {
                throw new \RuntimeException(sprintf('%s::handoverUserInOrganization is not available', $serviceClass));
            }
            $result = $service->handoverUserInOrganization($sourceUserId, $targetUserId, $organizationId, $remapDeckContent);
        } else {
            throw new \RuntimeException(sprintf('Unsupported handover service class %s', $serviceClass));
        }

        return is_array($result) ? $result : ['result' => $result];
    }

    /**
     * @param array<string,mixed> $values
     */
    private function insertJob(array $values): int
    {
        $insert = $this->db->getQueryBuilder();
        $insert->insert(self::JOBS_TABLE)
            ->values([
                'organization_id' => $insert->createNamedParameter($values['organization_id'], \PDO::PARAM_INT),
                'source_user_uid' => $insert->createNamedParameter($values['source_user_uid']),
                'target_user_uid' => $insert->createNamedParameter($values['target_user_uid']),
                'requested_by_uid' => $insert->createNamedParameter($values['requested_by_uid']),
                'status' => $insert->createNamedParameter($values['status']),
                'dry_run' => $insert->createNamedParameter((int) ((bool) $values['dry_run']), \PDO::PARAM_INT),
                'remove_source_from_groups' => $insert->createNamedParameter((int) ((bool) $values['remove_source_from_groups']), \PDO::PARAM_INT),
                'remap_deck_content' => $insert->createNamedParameter((int) ((bool) $values['remap_deck_content']), \PDO::PARAM_INT),
                'idempotency_key' => $insert->createNamedParameter($values['idempotency_key']),
                'request_fingerprint' => $insert->createNamedParameter($values['request_fingerprint']),
                'attempt' => $insert->createNamedParameter($values['attempt'], \PDO::PARAM_INT),
                'result_json' => $insert->createNamedParameter($values['result_json']),
                'error_message' => $insert->createNamedParameter($values['error_message']),
                'created_at' => $insert->createNamedParameter($values['created_at']),
                'updated_at' => $insert->createNamedParameter($values['updated_at']),
                'started_at' => $insert->createNamedParameter($values['started_at']),
                'finished_at' => $insert->createNamedParameter($values['finished_at']),
            ])
            ->executeStatement();

        $select = $this->db->getQueryBuilder();
        $result = $select->select('id')
            ->from(self::JOBS_TABLE)
            ->where($select->expr()->eq('organization_id', $select->createNamedParameter($values['organization_id'], \PDO::PARAM_INT)))
            ->andWhere($select->expr()->eq('source_user_uid', $select->createNamedParameter($values['source_user_uid'])))
            ->andWhere($select->expr()->eq('target_user_uid', $select->createNamedParameter($values['target_user_uid'])))
            ->andWhere($select->expr()->eq('requested_by_uid', $select->createNamedParameter($values['requested_by_uid'])))
            ->andWhere($select->expr()->eq('created_at', $select->createNamedParameter($values['created_at'])))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery();

        $idValue = $result->fetchOne();
        $result->closeCursor();

        if ($idValue === false) {
            throw new \RuntimeException('Failed to load created handover job');
        }

        return (int) $idValue;
    }

    /**
     * @param array<string,mixed> $fields
     */
    private function updateJob(int $jobId, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update(self::JOBS_TABLE);

        foreach ($fields as $column => $value) {
            if ($column === 'attempt') {
                $qb->set($column, $qb->createNamedParameter($value, \PDO::PARAM_INT));
                continue;
            }
            $qb->set($column, $qb->createNamedParameter($value));
        }

        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, \PDO::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * @param array<string,mixed>|null $payload
     */
    private function insertEvent(int $jobId, string $level, string $message, ?array $payload = null, ?string $stepKey = null): void
    {
        $sequenceNo = $this->nextEventSequenceNo($jobId);

        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::EVENTS_TABLE)
            ->values([
                'job_id' => $qb->createNamedParameter($jobId, \PDO::PARAM_INT),
                'sequence_no' => $qb->createNamedParameter($sequenceNo, \PDO::PARAM_INT),
                'step_key' => $qb->createNamedParameter($stepKey),
                'level' => $qb->createNamedParameter($level),
                'message' => $qb->createNamedParameter($message),
                'payload_json' => $qb->createNamedParameter($payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR)),
                'created_at' => $qb->createNamedParameter($this->utcNow()),
            ])
            ->executeStatement();
    }

    private function nextEventSequenceNo(int $jobId): int
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select($qb->createFunction('MAX(sequence_no)'))
            ->from(self::EVENTS_TABLE)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, \PDO::PARAM_INT)))
            ->executeQuery();

        $value = $result->fetchOne();
        $result->closeCursor();

        return $value === false || $value === null ? 1 : ((int) $value + 1);
    }

    /**
     * @param array<string,mixed> $fields
     */
    private function upsertStep(int $jobId, string $stepKey, array $fields): void
    {
        $existing = $this->getStepRow($jobId, $stepKey);

        if ($existing === null) {
            $insert = $this->db->getQueryBuilder();
            $insert->insert(self::STEPS_TABLE)
                ->values([
                    'job_id' => $insert->createNamedParameter($jobId, \PDO::PARAM_INT),
                    'step_key' => $insert->createNamedParameter($stepKey),
                    'status' => $insert->createNamedParameter((string) ($fields['status'] ?? 'queued')),
                    'attempt' => $insert->createNamedParameter((int) ($fields['attempt'] ?? 1), \PDO::PARAM_INT),
                    'retriable' => $insert->createNamedParameter((int) ((bool) ($fields['retriable'] ?? true)), \PDO::PARAM_INT),
                    'result_json' => $insert->createNamedParameter($fields['result_json'] ?? null),
                    'error_message' => $insert->createNamedParameter($fields['error_message'] ?? null),
                    'started_at' => $insert->createNamedParameter($fields['started_at'] ?? null),
                    'finished_at' => $insert->createNamedParameter($fields['finished_at'] ?? null),
                    'updated_at' => $insert->createNamedParameter($fields['updated_at'] ?? $this->utcNow()),
                ])
                ->executeStatement();

            return;
        }

        $update = $this->db->getQueryBuilder();
        $update->update(self::STEPS_TABLE);

        foreach ($fields as $column => $value) {
            if ($column === 'attempt') {
                $update->set($column, $update->createNamedParameter((int) $value, \PDO::PARAM_INT));
            } elseif ($column === 'retriable') {
                $update->set($column, $update->createNamedParameter((int) ((bool) $value), \PDO::PARAM_INT));
            } else {
                $update->set($column, $update->createNamedParameter($value));
            }
        }

        $update->where($update->expr()->eq('job_id', $update->createNamedParameter($jobId, \PDO::PARAM_INT)))
            ->andWhere($update->expr()->eq('step_key', $update->createNamedParameter($stepKey)))
            ->executeStatement();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getStepRow(int $jobId, string $stepKey): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::STEPS_TABLE)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('step_key', $qb->createNamedParameter($stepKey)))
            ->setMaxResults(1)
            ->executeQuery();

        $row = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function listStepRows(int $jobId): array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::STEPS_TABLE)
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, \PDO::PARAM_INT)))
            ->executeQuery();

        $rows = [];
        while (($row = $result->fetch()) !== false) {
            $rows[(string) $row['step_key']] = $row;
        }
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
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, \PDO::PARAM_INT)))
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
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();

        $row = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findJobByIdempotencyKey(int $organizationId, string $idempotencyKey): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')
            ->from(self::JOBS_TABLE)
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('idempotency_key', $qb->createNamedParameter($idempotencyKey)))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery();

        $row = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string,mixed>
     */
    private function mapJobRow(array $row, bool $includeSteps): array
    {
        $jobId = (int) $row['id'];

        return [
            'jobId' => $jobId,
            'organizationId' => (int) $row['organization_id'],
            'sourceUserId' => (string) $row['source_user_uid'],
            'targetUserId' => (string) $row['target_user_uid'],
            'requestedByUserId' => (string) $row['requested_by_uid'],
            'status' => (string) $row['status'],
            'dryRun' => $this->toBool($row['dry_run']),
            'removeSourceFromGroups' => $this->toBool($row['remove_source_from_groups']),
            'remapDeckContent' => $this->toBool($row['remap_deck_content']),
            'idempotencyKey' => $row['idempotency_key'],
            'requestFingerprint' => $row['request_fingerprint'],
            'attempt' => (int) ($row['attempt'] ?? 1),
            'result' => $this->decodeJsonNullable($row['result_json']),
            'errorMessage' => $row['error_message'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
            'startedAt' => $row['started_at'],
            'finishedAt' => $row['finished_at'],
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
            ->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, \PDO::PARAM_INT)))
            ->orderBy('id', 'ASC')
            ->executeQuery();

        $steps = [];
        while (($row = $result->fetch()) !== false) {
            $steps[] = [
                'id' => (int) $row['id'],
                'jobId' => (int) $row['job_id'],
                'stepKey' => (string) $row['step_key'],
                'status' => (string) $row['status'],
                'attempt' => (int) $row['attempt'],
                'retriable' => $this->toBool($row['retriable']),
                'result' => $this->decodeJsonNullable($row['result_json']),
                'errorMessage' => $row['error_message'],
                'startedAt' => $row['started_at'],
                'finishedAt' => $row['finished_at'],
                'updatedAt' => $row['updated_at'],
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
            'id' => (int) $row['id'],
            'jobId' => (int) $row['job_id'],
            'sequenceNo' => (int) ($row['sequence_no'] ?? 1),
            'stepKey' => $row['step_key'],
            'level' => (string) $row['level'],
            'message' => (string) $row['message'],
            'payload' => $this->decodeJsonNullable($row['payload_json']),
            'createdAt' => $row['created_at'],
        ];
    }

    private function normalizeIdempotencyKey(?string $idempotencyKey): ?string
    {
        if ($idempotencyKey === null) {
            return null;
        }

        $normalized = trim($idempotencyKey);
        if ($normalized === '') {
            return null;
        }

        return strlen($normalized) > 128 ? substr($normalized, 0, 128) : $normalized;
    }

    private function buildRequestFingerprint(
        int $organizationId,
        string $sourceUserId,
        string $targetUserId,
        bool $dryRun,
        bool $removeSourceFromGroups,
        bool $remapDeckContent,
    ): string {
        $payload = json_encode([
            'organizationId' => $organizationId,
            'sourceUserId' => $sourceUserId,
            'targetUserId' => $targetUserId,
            'options' => [
                'dryRun' => $dryRun,
                'removeSourceFromGroups' => $removeSourceFromGroups,
                'remapDeckContent' => $remapDeckContent,
            ],
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }

    /**
     * @return mixed
     */
    private function decodeJsonNullable(?string $value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param mixed $value
     */
    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes'], true);
        }

        return false;
    }

    private function utcNow(): string
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
