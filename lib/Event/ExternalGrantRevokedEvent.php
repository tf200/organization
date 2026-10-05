<?php

declare(strict_types=1);

namespace OCA\Organization\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched when an external collaborator loses access to a project.
 * The project app removes the user from the project.
 */
class ExternalGrantRevokedEvent extends Event
{
    public const REASON_REVOKED = 'revoked';
    public const REASON_EXPIRED = 'expired';

    public function __construct(
        private int $organizationId,
        private int $projectId,
        private string $userId,
        private string $reason,
    ) {
        parent::__construct();
    }

    public function getOrganizationId(): int
    {
        return $this->organizationId;
    }

    public function getProjectId(): int
    {
        return $this->projectId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
