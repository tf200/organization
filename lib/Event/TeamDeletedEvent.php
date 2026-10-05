<?php

declare(strict_types=1);

namespace OCA\Organization\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched after a team, its members and its project assignments were deleted.
 */
class TeamDeletedEvent extends Event
{
    public function __construct(
        private int $organizationId,
        private int $teamId,
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
}
