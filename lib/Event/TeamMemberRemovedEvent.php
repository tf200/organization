<?php

declare(strict_types=1);

namespace OCA\Organization\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched after a user was removed from a team.
 */
class TeamMemberRemovedEvent extends Event
{
    public function __construct(
        private int $organizationId,
        private int $teamId,
        private string $userId,
    ) {
        parent::__construct();
    }

    public function getOrganizationId(): int
    {
        return $this->organizationId;
    }

    public function getTeamId(): int
    {
        return $this->teamId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }
}
