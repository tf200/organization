<?php

declare(strict_types=1);

namespace OCA\Organization\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched 30 days after an external collaborator lost access to a project.
 * The project app moves the external's private project folder to the project
 * owner and calls markReleased(); until then the account is not deleted.
 */
class ExternalPrivateFolderReleaseEvent extends Event
{
    private bool $released = false;

    public function __construct(
        private int $organizationId,
        private int $projectId,
        private string $userId,
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

    /**
     * The folder was moved, or there was none to move.
     */
    public function markReleased(): void
    {
        $this->released = true;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }
}
