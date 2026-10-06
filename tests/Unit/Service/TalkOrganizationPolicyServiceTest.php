<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Service;

use OCA\Organization\Db\ExternalGrant;
use OCA\Organization\Db\UserMapper;
use OCA\Organization\Service\ExternalCollaboratorService;
use OCA\Organization\Service\TalkOrganizationPolicyService;

use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TalkOrganizationPolicyServiceTest extends TestCase
{
    private UserMapper&MockObject $userMapper;
    private IGroupManager&MockObject $groupManager;
    private TalkOrganizationPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 5) . '/lib/public/IGroupManager.php';

        $this->userMapper = $this->createMock(UserMapper::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

        $this->service = new TalkOrganizationPolicyService(
            $this->userMapper,
            $this->groupManager,
        );
    }

    public function testCanUserUseTalkAllowsGlobalAdmin(): void
    {
        $this->groupManager->expects($this->once())
            ->method('isAdmin')
            ->with('admin')
            ->willReturn(true);
        $this->userMapper->expects($this->never())
            ->method('getOrganizationMembership');

        $this->assertTrue($this->service->canUserUseTalk('admin'));
    }

    public function testCanUsersCommunicateRequiresSharedOrganization(): void
    {
        $this->groupManager->expects($this->exactly(4))
            ->method('isAdmin')
            ->willReturn(false);
        $this->userMapper->expects($this->once())
            ->method('getOrganizationMemberships')
            ->with(['alice', 'bob'])
            ->willReturn([
                'alice' => ['organization_id' => 7, 'role' => 'member'],
                'bob' => ['organization_id' => 7, 'role' => 'member'],
            ]);

        $this->assertTrue($this->service->canUsersCommunicate('alice', 'bob'));
    }

    public function testCanUserAccessRoomRejectsCrossOrganizationRoom(): void
    {
        $this->groupManager->expects($this->atLeast(1))
            ->method('isAdmin')
            ->willReturn(false);
        $this->userMapper->expects($this->atLeast(1))
            ->method('getOrganizationMembership')
            ->with('alice')
            ->willReturn(['organization_id' => 7, 'role' => 'member']);
        $this->userMapper->expects($this->atLeast(1))
            ->method('getOrganizationMemberships')
            ->willReturnCallback(static function (array $userIds): array {
                $memberships = [];
                foreach ($userIds as $userId) {
                    $memberships[$userId] = [
                        'organization_id' => $userId === 'bob' ? 8 : 7,
                        'role' => 'member',
                    ];
                }

                return $memberships;
            });

        $this->assertFalse($this->service->canUserAccessRoom('alice', ['alice', 'bob']));
        $this->assertTrue($this->service->canUserAccessRoom('alice', ['alice', 'charlie']));
    }

    /**
     * Organizations 7 (alice, bob, admin dana) and 8 (carol, admin erin).
     * Organization 7 granted ext and ext2, 8 granted extb. Project group proj-a holds
     * alice and the externals ext, ext2 and lapsed, whose grant no longer works;
     * proj-b holds carol and extb. stranger is in proj-a without any role.
     */
    private function externalWorld(): TalkOrganizationPolicyService
    {
        $memberships = [
            'alice' => ['organization_id' => 7, 'role' => 'member'],
            'bob' => ['organization_id' => 7, 'role' => 'member'],
            'carol' => ['organization_id' => 8, 'role' => 'member'],
            'dana' => ['organization_id' => 7, 'role' => 'admin'],
            'erin' => ['organization_id' => 8, 'role' => 'admin'],
        ];
        $groups = [
            'alice' => ['proj-a'],
            'carol' => ['proj-b'],
            'ext' => ['externals', 'proj-a'],
            'ext2' => ['externals', 'proj-a'],
            'extb' => ['externals', 'proj-b'],
            'lapsed' => ['externals', 'proj-a'],
            'stranger' => ['proj-a'],
        ];
        $usable = ['ext' => 7, 'ext2' => 7, 'extb' => 8];

        $userMapper = $this->createMock(UserMapper::class);
        $userMapper->method('getOrganizationMembership')->willReturnCallback(
            static fn (string $uid): ?array => $memberships[$uid] ?? null,
        );
        $userMapper->method('getOrganizationMemberships')->willReturnCallback(
            static fn (array $uids): array => array_intersect_key($memberships, array_flip($uids)),
        );

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(false);
        $groupManager->method('getUserGroupIds')->willReturnCallback(
            static fn (IUser $user): array => $groups[$user->getUID()] ?? [],
        );
        $groupManager->method('isInGroup')->willReturnCallback(
            static fn (string $uid, string $gid): bool => in_array($gid, $groups[$uid] ?? [], true),
        );

        $externals = $this->createMock(ExternalCollaboratorService::class);
        $externals->method('getUsableGrants')->willReturnCallback(
            static function (string $uid) use ($usable): array {
                if (!isset($usable[$uid])) {
                    return [];
                }
                $grant = new ExternalGrant();
                $grant->setOrganizationId($usable[$uid]);
                return [$grant];
            },
        );

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturnCallback(function (string $uid): IUser {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            return $user;
        });

        return new TalkOrganizationPolicyService($userMapper, $groupManager, $externals, $userManager);
    }

    public function testExternalWithUsableGrantCanUseTalk(): void
    {
        $service = $this->externalWorld();

        $this->assertTrue($service->canUserUseTalk('ext'));
        $this->assertFalse($service->canUserUseTalk('lapsed'));
        $this->assertFalse($service->canUserUseTalk('stranger'));
    }

    public static function externalPairs(): array
    {
        return [
            'external and project co-member' => ['ext', 'alice', true],
            'project co-member and external' => ['alice', 'ext', true],
            'two externals on the same project' => ['ext', 'ext2', true],
            'external and member outside the project' => ['ext', 'bob', false],
            'external and member of another organization' => ['ext', 'carol', false],
            'externals on different projects' => ['ext', 'extb', false],
            'external whose grant lapsed' => ['lapsed', 'alice', false],
            'external and co-external whose grant lapsed' => ['ext', 'lapsed', false],
            'external and group member without a role' => ['ext', 'stranger', false],
            'external and admin of the granting organization' => ['ext', 'dana', true],
            'external and admin of another organization' => ['ext', 'erin', false],
            'members of one organization' => ['alice', 'bob', true],
        ];
    }

    /** @dataProvider externalPairs */
    public function testExternalsTalkOnlyWithProjectCoMembers(string $first, string $second, bool $expected): void
    {
        $this->assertSame($expected, $this->externalWorld()->canUsersCommunicate($first, $second));
    }

    public function testExternalJoinsProjectRoomButNotWiderRooms(): void
    {
        $service = $this->externalWorld();

        $this->assertTrue($service->canUserAccessRoom('ext', ['alice', 'ext2']));
        $this->assertFalse($service->canUserAccessRoom('ext', ['alice', 'bob']));
        $this->assertFalse($service->canUserAccessRoom('bob', ['alice', 'ext']));
        $this->assertTrue($service->canUserAccessRoom('dana', ['alice', 'ext']));
    }

    public function testExternalSearchFindsOnlyCoMembers(): void
    {
        $this->assertSame(
            ['alice', 'ext2'],
            $this->externalWorld()->filterReachableUserIds('ext', ['alice', 'bob', 'carol', 'ext2', 'extb', 'stranger']),
        );
    }
}
