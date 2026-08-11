<?php

declare(strict_types=1);

namespace OCA\Organization\Event;

use OCP\EventDispatcher\Event;

class EntitlementsChangedEvent extends Event
{
    private function __construct(
        private ?int $organizationId,
        private ?int $planId,
    ) {
        parent::__construct();
    }

    public static function forOrganization(int $organizationId): self
    {
        return new self($organizationId, null);
    }

    public static function forPlan(int $planId): self
    {
        return new self(null, $planId);
    }

    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    public function getPlanId(): ?int
    {
        return $this->planId;
    }
}
