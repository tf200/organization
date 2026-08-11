<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCP\AppFramework\OCS\OCSException;

class PlanEntitlementValidator
{
    /**
     * @throws OCSException
     */
    public function validate(
        ?int $maxMembers,
        ?int $maxProjects,
        ?int $sharedStoragePerProject,
        ?int $privateStoragePerUser,
    ): void {
        if ($maxMembers === null || $maxMembers < 1) {
            throw new OCSException('Max members must be at least 1', 104);
        }
        if ($maxProjects === null || $maxProjects < 1) {
            throw new OCSException('Max projects must be at least 1', 104);
        }
        if ($sharedStoragePerProject === null || $sharedStoragePerProject < 1) {
            throw new OCSException('Shared storage per project must be positive', 104);
        }
        if ($privateStoragePerUser === null || $privateStoragePerUser < 1) {
            throw new OCSException('Private storage per user must be positive', 104);
        }
    }
}
