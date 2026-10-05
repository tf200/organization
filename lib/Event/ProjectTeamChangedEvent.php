<?php

declare(strict_types=1);

namespace OCA\Organization\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched after a project's team was assigned, switched or unassigned.
 */
class ProjectTeamChangedEvent extends Event
{
    public function __construct(
        private int $organizationId,
        private int $projectId,
        private ?int $previousTeamId,
        private ?int $teamId,
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

    public function getPreviousTeamId(): ?int
    {
        return $this->previousTeamId;
    }

    public function getTeamId(): ?int
    {
        return $this->teamId;
    }
}
