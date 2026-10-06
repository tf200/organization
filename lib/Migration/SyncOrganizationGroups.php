<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use OCA\Organization\Service\OrganizationGroupService;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Backfills the organization member groups. Safe to run on every upgrade.
 */
class SyncOrganizationGroups implements IRepairStep
{
    public function __construct(
        private OrganizationGroupService $organizationGroupService,
    ) {
    }

    public function getName(): string
    {
        return 'Sync organization member groups';
    }

    public function run(IOutput $output): void
    {
        // A failed backfill must not block the upgrade; the next run catches up.
        try {
            $count = $this->organizationGroupService->syncAll();
            $output->info(sprintf('Synced organization groups for %d users', $count));
        } catch (\Throwable $e) {
            $output->warning('Could not sync organization groups: ' . $e->getMessage());
        }
    }
}
