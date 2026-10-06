<?php

declare(strict_types=1);

namespace OCA\Organization\BackgroundJob;

use OCA\Organization\Service\ExternalLifecycleService;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Daily: ends stale invitations, hands private folders to project owners, and
 * disables and later deletes external accounts nobody uses any more.
 */
class CleanUpExternalAccounts extends TimedJob
{
    public function __construct(
        protected ITimeFactory $time,
        private ExternalLifecycleService $lifecycleService,
    ) {
        parent::__construct($time);
        $this->setInterval(86400);
    }

    protected function run($argument): void
    {
        $this->lifecycleService->dropStaleInvites();
        $this->lifecycleService->releaseFolders();
        $this->lifecycleService->disableIdleAccounts();
        $this->lifecycleService->deleteDisabledAccounts();
    }
}
