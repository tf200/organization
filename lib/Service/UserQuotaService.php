<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCP\Files\FileInfo;
use OCP\IConfig;
use OCP\Util;

use OCA\Organization\Db\PlanMapper;
use OCA\Organization\Db\SubscriptionMapper;
use OCA\Organization\Db\UserMapper;

class UserQuotaService
{
    /** @var array<string,?int> */
    private array $quotaCache = [];

    public function __construct(
        private UserMapper $userMapper,
        private SubscriptionMapper $subscriptionMapper,
        private PlanMapper $planMapper,
        private IConfig $config,
    ) {
    }

    /**
     * Returns the organization-enforced home quota in bytes, or null when the
     * user is not managed by an organization.
     */
    public function getEffectiveQuota(string $userId): ?int
    {
        if (array_key_exists($userId, $this->quotaCache)) {
            return $this->quotaCache[$userId];
        }

        $membership = $this->userMapper->getOrganizationMembership($userId);
        if ($membership === null) {
            return $this->quotaCache[$userId] = null;
        }

        $subscription = $this->subscriptionMapper->findByOrganizationId($membership['organization_id']);
        if ($subscription === null) {
            return $this->quotaCache[$userId] = null;
        }

        $plan = $this->planMapper->find($subscription->getPlanId());
        if ($plan === null) {
            return $this->quotaCache[$userId] = null;
        }

        $planQuota = $plan->getPrivateStoragePerUser();
        $administrativeQuota = $this->getAdministrativeQuota($userId);

        if ($administrativeQuota === null || $administrativeQuota < 0) {
            return $this->quotaCache[$userId] = $planQuota;
        }

        return $this->quotaCache[$userId] = min($administrativeQuota, $planQuota);
    }

    /**
     * Resolve the configured quota without calling IUser::getQuota(), which
     * would dispatch GetQuotaEvent recursively.
     */
    private function getAdministrativeQuota(string $userId): ?int
    {
        $quota = $this->config->getUserValue($userId, 'files', 'quota', 'default');
        if ($quota === 'default') {
            $quota = $this->config->getAppValue('files', 'default_quota', 'none');
        }

        if ($quota === 'none') {
            return FileInfo::SPACE_UNLIMITED;
        }

        $bytes = Util::computerFileSize($quota);

        return $bytes === false ? null : (int) $bytes;
    }
}
