<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCA\Organization\Db\ExternalAuditEntry;
use OCA\Organization\Db\ExternalAuditMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes down what happens to external collaborators: once in the app's own
 * table, which organization admins can read, and once in Nextcloud's audit log
 * when the admin_audit app is on.
 *
 * Recording never stops the action it records.
 */
class ExternalAuditService
{
    public const INVITED = 'invited';
    public const INVITE_RESENT = 'invite_resent';
    public const ACCEPTED = 'accepted';
    public const END_DATE_CHANGED = 'end_date_changed';
    public const ROLES_CHANGED = 'roles_changed';
    public const REVOKED = 'revoked';
    public const EXPIRED = 'expired';
    public const LINK_REQUESTED = 'link_requested';
    public const ACCOUNT_DISABLED = 'account_disabled';
    public const ACCOUNT_DELETED = 'account_deleted';

    public function __construct(
        private ExternalAuditMapper $mapper,
        private IEventDispatcher $eventDispatcher,
        private ITimeFactory $timeFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param ?string $actorUid who did it; null for a background job
     * @param array<string,mixed> $details
     */
    public function record(
        string $action,
        string $userUid,
        ?int $organizationId,
        ?int $projectId,
        ?string $actorUid,
        array $details = [],
    ): void {
        try {
            $entry = new ExternalAuditEntry();
            $entry->setAction($action);
            $entry->setUserUid($userUid);
            $entry->setOrganizationId($organizationId);
            $entry->setProjectId($projectId);
            $entry->setActorUid($actorUid);
            $entry->setDetails($details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR));
            $entry->setCreatedAt($this->timeFactory->getDateTime('now', new \DateTimeZone('UTC')));
            $this->mapper->insert($entry);
        } catch (Throwable $e) {
            $this->logger->warning('Could not record an external collaborator audit entry', [
                'action' => $action,
                'userId' => $userUid,
                'exception' => $e,
            ]);
        }

        // admin_audit skips the line when a parameter is null, so every value is a string.
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
            'External collaborator %s: %s (organization %s, project %s, by %s)',
            [
                'userId' => $userUid,
                'action' => $action,
                'organizationId' => $organizationId === null ? '-' : (string) $organizationId,
                'projectId' => $projectId === null ? '-' : (string) $projectId,
                'actor' => $actorUid ?? 'system',
            ],
        ));
    }

    /** @return ExternalAuditEntry[] newest first */
    public function listForOrganization(int $organizationId, int $limit = 100): array
    {
        return $this->mapper->findByOrganization($organizationId, $limit);
    }

    public function lastOf(string $userUid, string $action): ?ExternalAuditEntry
    {
        return $this->mapper->findLatest($userUid, $action);
    }
}
