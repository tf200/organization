<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Service;

use OCA\Organization\Service\ExternalIsolationService;
use OCA\Organization\Service\OrganizationGroupService;

use OCP\App\IAppManager;
use OCP\IAppConfig;

use PHPUnit\Framework\TestCase;

class ExternalIsolationServiceTest extends TestCase
{
    /** @var array<string,string> */
    private array $config = [];

    /** @var array<string,string[]|null> app => groups, [] for everyone, null when disabled */
    private array $apps = ['mail' => [], 'calendar' => ['staff'], 'contacts' => null, 'tasks' => []];

    private OrganizationGroupService $groups;
    private ExternalIsolationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default,
        );
        $appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
            $this->config[$key] = $value;
            return true;
        });

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForAnyone')->willReturnCallback(fn (string $app): bool => ($this->apps[$app] ?? null) !== null);
        $appManager->method('getAppRestriction')->willReturnCallback(fn (string $app): array => $this->apps[$app] ?? []);
        $appManager->method('enableAppForGroups')->willReturnCallback(function (string $app, array $groups): void {
            $this->apps[$app] = $groups;
        });

        $this->groups = $this->createMock(OrganizationGroupService::class);
        $this->service = new ExternalIsolationService($appConfig, $appManager, $this->groups);
    }

    public function testApplyLimitsSearchAndOpenStaffApps(): void
    {
        $this->config['shareapi_only_share_with_group_members_exclude_group_list'] = '["legacy"]';
        $this->groups->expects($this->once())->method('syncAll');

        $changes = $this->service->apply();

        $this->assertCount(4, $changes);
        $this->assertSame('yes', $this->config['shareapi_restrict_user_enumeration_to_group']);
        $this->assertSame(
            ['legacy', 'externals', 'organization-members', 'organization-admins'],
            json_decode($this->config['shareapi_only_share_with_group_members_exclude_group_list'], true),
        );
        $this->assertSame(['organization-members', 'admin'], $this->apps['mail']);
        $this->assertSame(['organization-members', 'admin'], $this->apps['tasks']);
        $this->assertSame(['staff'], $this->apps['calendar'], 'An app already limited to groups is left alone');
        $this->assertNull($this->apps['contacts'], 'A disabled app stays disabled');
    }

    public function testNothingPendingAfterApply(): void
    {
        $this->service->apply();

        $this->assertSame([], $this->service->pendingChanges());
    }
}
