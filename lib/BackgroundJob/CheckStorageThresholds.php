<?php

declare(strict_types=1);

namespace OCA\Organization\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

use OCA\Organization\Service\StorageThresholdService;

final class CheckStorageThresholds extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private StorageThresholdService $storageThresholdService,
    ) {
        parent::__construct($time);
        $this->setInterval(3600);
    }

    protected function run($argument): void
    {
        $this->storageThresholdService->check();
    }
}
