<?php

declare(strict_types=1);

namespace OCA\Organization\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched after a user was removed from an organization, so apps can
 * revoke access they granted through that membership.
 */
class OrganizationMemberRemovedEvent extends Event
{
    public function __construct(
        private int $organizationId,
        private string $userId,
    ) {
        parent::__construct();
    }

    public function getOrganizationId(): int
    {
        return $this->organizationId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }
}
