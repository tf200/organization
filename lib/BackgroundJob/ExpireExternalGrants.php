<?php

declare(strict_types=1);

namespace OCA\Organization\BackgroundJob;

use OCA\Organization\Service\ExternalLifecycleService;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Hourly: warns a week before an external's access ends, then ends it.
 */
class ExpireExternalGrants extends TimedJob
{
    public function __construct(
        protected ITimeFactory $time,
        private ExternalLifecycleService $lifecycleService,
    ) {
        parent::__construct($time);
        $this->setInterval(3600);
    }

    protected function run($argument): void
    {
        $this->lifecycleService->warnExpiring();
        $this->lifecycleService->expireDue();
    }
}
