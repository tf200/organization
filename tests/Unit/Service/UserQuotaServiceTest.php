<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Service;

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
}
