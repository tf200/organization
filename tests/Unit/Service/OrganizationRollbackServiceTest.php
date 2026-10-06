<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Service;

use OCA\Organization\Event\ExternalGrantActivatedEvent;
use OCA\Organization\Event\ExternalGrantRevokedEvent;
use OCA\Organization\Service\OrganizationRollbackService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

class OrganizationRollbackServiceTest extends TestCase
{
    private OrganizationRollbackService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new \ReflectionClass(OrganizationRollbackService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();
    }

    public function testBuildApplyValidationFailureResultPreservesPreviewDetails(): void
    {
        $result = $this->invokePrivate('buildApplyValidationFailureResult', [[
            'canApply' => false,
            'errors' => ['Archive organization does not match target organization'],
            'warnings' => ['Project 10 has no file entries in archive'],
            'impact' => [
                'members' => 4,
                'projects' => 2,
            ],
        ], 'apply', 77]);

        self::assertSame('apply', $result['mode']);
        self::assertSame(77, $result['sourceBackupJobId']);
        self::assertFalse($result['canApply']);
        self::assertSame(['Archive organization does not match target organization'], $result['validationErrors']);
        self::assertSame(['Project 10 has no file entries in archive'], $result['warnings']);
        self::assertSame(['members' => 4, 'projects' => 2], $result['impact']);
    }

    public function testBuildApplyValidationFailureResultDropsBlankMessages(): void
    {
        $result = $this->invokePrivate('buildApplyValidationFailureResult', [[
            'canApply' => false,
            'errors' => ['Primary error', '', '   ', 12],
            'warnings' => ['Warning', null],
            'impact' => 'invalid',
        ], 'apply', 88]);

        self::assertSame(['Primary error'], $result['validationErrors']);
        self::assertSame(['Warning'], $result['warnings']);
        self::assertSame([], $result['impact']);
    }

    public function testBuildApplyValidationFailureEventPayloadNormalizesStructure(): void
    {
        $payload = $this->invokePrivate('buildApplyValidationFailureEventPayload', [[
            'mode' => 'apply',
            'sourceBackupJobId' => 91,
            'canApply' => false,
            'validationErrors' => ['Referenced plan IDs are missing: 3'],
            'warnings' => ['Project 8 has no file entries in archive'],
            'impact' => ['projectFiles' => 12],
        ]]);

        self::assertSame('apply', $payload['mode']);
        self::assertSame(91, $payload['sourceBackupJobId']);
        self::assertFalse($payload['canApply']);
        self::assertSame(['Referenced plan IDs are missing: 3'], $payload['validationErrors']);
        self::assertSame(['Project 8 has no file entries in archive'], $payload['warnings']);
        self::assertSame(['projectFiles' => 12], $payload['impact']);
    }

    public function testDetermineProjectRestoreFolderPathPrefersArchivedFolderPath(): void
    {
        $path = $this->invokePrivate('determineProjectRestoreFolderPath', [[
            'folder_path' => '/Projects/Archived Migration/',
            'name' => 'Ignored Name',
        ]]);

        self::assertSame('Projects/Archived Migration', $path);
    }

    public function testDetermineProjectRestoreFolderPathFallsBackToProjectName(): void
    {
        $path = $this->invokePrivate('determineProjectRestoreFolderPath', [[
            'folder_path' => '',
            'name' => 'Restored Project',
        ]]);

        self::assertSame('Restored Project', $path);
    }

    public function testExternalsLeaveOrRejoinProjectsAsTheRestoreDecides(): void
    {
        $events = [];
        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(static function (object $event) use (&$events): void {
            $events[] = $event;
        });
        (new \ReflectionProperty($this->service, 'eventDispatcher'))->setValue($this->service, $dispatcher);

        $row = static fn (int $projectId, string $uid): array => [
            'organization_id' => 5,
            'project_id' => $projectId,
            'user_uid' => $uid,
            'functional_role_keys' => '["site_lead"]',
            'drasci_roles' => '["informed"]',
        ];
        $before = ['39:klaas' => $row(39, 'klaas'), '39:newcomer' => $row(39, 'newcomer')];
        $after = ['39:klaas' => $row(39, 'klaas'), '41:returning' => $row(41, 'returning')];

        $this->invokePrivate('reconcileExternalAccess', [$before, $after]);

        $this->assertCount(2, $events);
        $this->assertInstanceOf(ExternalGrantRevokedEvent::class, $events[0]);
        $this->assertSame('newcomer', $events[0]->getUserId());
        $this->assertInstanceOf(ExternalGrantActivatedEvent::class, $events[1]);
        $this->assertSame([41, 'returning'], [$events[1]->getProjectId(), $events[1]->getUserId()]);
        $this->assertSame(['site_lead'], $events[1]->getFunctionalRoleKeys());
    }

    /**
     * @param list<mixed> $args
     * @return mixed
     */
    private function invokePrivate(string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($this->service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->service, $args);
    }
}
