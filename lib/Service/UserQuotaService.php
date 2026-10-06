<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCP\Files\FileInfo;
use OCP\IConfig;
use OCP\Util;

use OCA\Organization\Db\ExternalGrant;
use OCA\Organization\Db\ExternalGrantMapper;
use OCA\Organization\Db\ExternalMapper;
use OCA\Organization\Db\Plan;
use OCA\Organization\Db\PlanMapper;
use OCA\Organization\Db\SubscriptionMapper;
use OCA\Organization\Db\UserMapper;

class UserQuotaService
{
    /** Used when a plan sets no storage for externals: 1 GiB. */
    public const DEFAULT_EXTERNAL_QUOTA = 1073741824;

    /** @var array<string,?int> */
    private array $quotaCache = [];

    public function __construct(
        private UserMapper $userMapper,
        private SubscriptionMapper $subscriptionMapper,
        private PlanMapper $planMapper,
        private IConfig $config,
        private ?ExternalMapper $externalMapper = null,
        private ?ExternalGrantMapper $grantMapper = null,
    ) {
    }

    /**
     * Returns the organization-enforced home quota in bytes, or null when the
     * user is neither an organization member nor an external collaborator.
     */
    public function getEffectiveQuota(string $userId): ?int
    {
        if (array_key_exists($userId, $this->quotaCache)) {
            return $this->quotaCache[$userId];
        }

        $membership = $this->userMapper->getOrganizationMembership($userId);
        if ($membership === null) {
            $externalQuota = $this->getExternalQuota($userId);
            return $this->quotaCache[$userId] = $externalQuota === null ? null : $this->capByAdministrativeQuota($userId, $externalQuota);
        }

        $plan = $this->findPlan($membership['organization_id']);
        if ($plan === null) {
            return $this->quotaCache[$userId] = null;
        }

        return $this->quotaCache[$userId] = $this->capByAdministrativeQuota($userId, $plan->getPrivateStoragePerUser());
    }

    /**
     * An external gets the largest external quota among the organizations
     * that invited them, or the default when no plan sets one. Null when the
     * user is not an external.
     */
    private function getExternalQuota(string $userId): ?int
    {
        if ($this->externalMapper?->findByUserUid($userId) === null) {
            return null;
        }

        $default = (int) $this->config->getAppValue('organization', 'external_storage_quota_default', (string) self::DEFAULT_EXTERNAL_QUOTA);
        $quota = null;
        $grants = $this->grantMapper?->findByUser($userId, [ExternalGrant::STATUS_PENDING, ExternalGrant::STATUS_ACTIVE]) ?? [];
        foreach (array_unique(array_map(static fn (ExternalGrant $grant): int => $grant->getOrganizationId(), $grants)) as $organizationId) {
            $planQuota = $this->findPlan($organizationId)?->getExternalStorageQuota() ?? $default;
            $quota = max($quota ?? 0, $planQuota);
        }

        return $quota ?? $default;
    }

    private function findPlan(int $organizationId): ?Plan
    {
        $subscription = $this->subscriptionMapper->findByOrganizationId($organizationId);
        return $subscription === null ? null : $this->planMapper->find($subscription->getPlanId());
    }

    private function capByAdministrativeQuota(string $userId, int $planQuota): int
    {
        $administrativeQuota = $this->getAdministrativeQuota($userId);

        if ($administrativeQuota === null || $administrativeQuota < 0) {
            return $planQuota;
        }

        return min($administrativeQuota, $planQuota);
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
