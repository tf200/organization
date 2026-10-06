<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Service;

use OCA\Organization\Db\External;
use OCA\Organization\Db\ExternalGrant;
use OCA\Organization\Db\ExternalGrantMapper;
use OCA\Organization\Db\ExternalMapper;
use OCA\Organization\Db\Plan;
use OCA\Organization\Db\PlanMapper;
use OCA\Organization\Db\Subscription;
use OCA\Organization\Db\SubscriptionMapper;
use OCA\Organization\Db\UserMapper;
use OCA\Organization\Service\UserQuotaService;

use OCP\IConfig;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserQuotaServiceTest extends TestCase
{
    private UserMapper&MockObject $userMapper;
    private SubscriptionMapper&MockObject $subscriptionMapper;
    private PlanMapper&MockObject $planMapper;
    private IConfig&MockObject $config;
    private UserQuotaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userMapper = $this->createMock(UserMapper::class);
        $this->subscriptionMapper = $this->createMock(SubscriptionMapper::class);
        $this->planMapper = $this->createMock(PlanMapper::class);
        $this->config = $this->createMock(IConfig::class);
        $this->service = new UserQuotaService(
            $this->userMapper,
            $this->subscriptionMapper,
            $this->planMapper,
            $this->config,
        );
    }

    public function testReturnsNullForUserWithoutOrganization(): void
    {
        $this->userMapper->method('getOrganizationMembership')->with('alice')->willReturn(null);
        $this->subscriptionMapper->expects($this->never())->method('findByOrganizationId');

        $this->assertNull($this->service->getEffectiveQuota('alice'));
    }

    public function testPlanQuotaLimitsUnlimitedUser(): void
    {
        $this->configurePlanQuota(10 * 1024 * 1024);
        $this->config->method('getUserValue')->willReturn('default');
        $this->config->method('getAppValue')->willReturn('none');

        $this->assertSame(10 * 1024 * 1024, $this->service->getEffectiveQuota('alice'));
    }

    public function testLowerAdministrativeQuotaIsPreserved(): void
    {
        $this->configurePlanQuota(10 * 1024 * 1024);
        $this->config->method('getUserValue')->willReturn('5 MB');

        $this->assertSame(5 * 1024 * 1024, $this->service->getEffectiveQuota('alice'));
    }

    public function testPlanQuotaWinsWhenAdministrativeQuotaIsHigher(): void
    {
        $this->configurePlanQuota(10 * 1024 * 1024);
        $this->config->method('getUserValue')->willReturn('20 MB');

        $this->assertSame(10 * 1024 * 1024, $this->service->getEffectiveQuota('alice'));
    }

    private function configurePlanQuota(int $quota): void
    {
        $subscription = new Subscription();
        $subscription->setPlanId(3);

        $plan = new Plan();
        $plan->setPrivateStoragePerUser($quota);

        $this->userMapper->method('getOrganizationMembership')->with('alice')->willReturn([
            'organization_id' => 7,
            'role' => 'member',
        ]);
        $this->subscriptionMapper->method('findByOrganizationId')->with(7)->willReturn($subscription);
        $this->planMapper->method('find')->with(3)->willReturn($plan);
    }

    /**
     * Klaas holds grants from organizations 5 and 6; their plans give
     * externals the given quotas (null = plan sets none).
     */
    private function externalService(?int $quotaOrg5, ?int $quotaOrg6, string $userQuota = 'default'): UserQuotaService
    {
        $externals = $this->createMock(ExternalMapper::class);
        $externals->method('findByUserUid')->willReturnCallback(
            static fn (string $uid): ?External => $uid === 'klaas' ? new External() : null,
        );
        $grants = $this->createMock(ExternalGrantMapper::class);
        $grants->method('findByUser')->willReturnCallback(static function () use ($quotaOrg6): array {
            $result = [];
            foreach ($quotaOrg6 === -1 ? [5] : [5, 6] as $organizationId) {
                $grant = new ExternalGrant();
                $grant->setOrganizationId($organizationId);
                $result[] = $grant;
            }
            return $result;
        });
        $this->subscriptionMapper->method('findByOrganizationId')->willReturnCallback(static function (int $organizationId): Subscription {
            $subscription = new Subscription();
            $subscription->setPlanId($organizationId);
            return $subscription;
        });
        $this->planMapper->method('find')->willReturnCallback(static function (int $planId) use ($quotaOrg5, $quotaOrg6): Plan {
            $plan = new Plan();
            $plan->setExternalStorageQuota($planId === 5 ? $quotaOrg5 : $quotaOrg6);
            return $plan;
        });
        $this->userMapper->method('getOrganizationMembership')->willReturn(null);
        $this->config->method('getUserValue')->willReturn($userQuota);
        $this->config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default): string => $key === 'default_quota' ? 'none' : $default,
        );

        return new UserQuotaService($this->userMapper, $this->subscriptionMapper, $this->planMapper, $this->config, $externals, $grants);
    }

    public function testExternalGetsTheLargestQuotaOfTheInvitingOrganizations(): void
    {
        $this->assertSame(5 * 1024 * 1024 * 1024, $this->externalService(2 * 1024 * 1024 * 1024, 5 * 1024 * 1024 * 1024)->getEffectiveQuota('klaas'));
    }

    public function testExternalFallsBackToTheDefaultQuota(): void
    {
        $this->assertSame(UserQuotaService::DEFAULT_EXTERNAL_QUOTA, $this->externalService(null, -1)->getEffectiveQuota('klaas'));
    }

    public function testLowerAdministrativeQuotaAlsoLimitsExternals(): void
    {
        $this->assertSame(5 * 1024 * 1024, $this->externalService(null, -1, '5 MB')->getEffectiveQuota('klaas'));
    }

    public function testUserWhoIsNeitherMemberNorExternalStaysUnmanaged(): void
    {
        $this->assertNull($this->externalService(null, null)->getEffectiveQuota('stranger'));
    }
}
