<?php

declare(strict_types=1);

namespace OCA\Organization\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched when an external collaborator's project grant becomes active.
 * The project app adds the user to the project with the stored roles.
 */
class ExternalGrantActivatedEvent extends Event
{
    /**
     * @param string[] $functionalRoleKeys
     * @param string[] $drasciRoles
     */
    public function __construct(
        private int $organizationId,
        private int $projectId,
        private string $userId,
        private array $functionalRoleKeys,
        private array $drasciRoles,
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

    /** @return string[] */
    public function getFunctionalRoleKeys(): array
    {
        return $this->functionalRoleKeys;
    }

    /** @return string[] */
    public function getDrasciRoles(): array
    {
        return $this->drasciRoles;
    }
}
