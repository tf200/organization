<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Service;

use OCA\Organization\Db\Organization;
use OCA\Organization\Db\OrganizationMapper;
use OCA\Organization\Db\UserMapper;
use OCA\Organization\Service\OrganizationGroupService;

use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrganizationGroupServiceTest extends TestCase
{
    /** @var array<string,array{organization_id:int,role:string}> */
    private array $memberships = [];

    /** @var array<string,string[]> group => user IDs */
    private array $groups = [];

    private UserMapper&MockObject $userMapper;
    private OrganizationMapper&MockObject $organizationMapper;
    private IGroupManager&MockObject $groupManager;
    private OrganizationGroupService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userMapper = $this->createMock(UserMapper::class);
        $this->userMapper->method('getOrganizationMembership')->willReturnCallback(
            fn (string $uid): ?array => $this->memberships[$uid] ?? null,
        );
        $this->userMapper->method('getOrganizationMembers')->willReturnCallback(
            fn (int $organizationId): array => array_values(array_map(
                static fn (string $uid): array => ['user_uid' => $uid, 'role' => 'member', 'created_at' => null],
                array_keys(array_filter($this->memberships, static fn (array $m): bool => $m['organization_id'] === $organizationId)),
            )),
        );

        $this->organizationMapper = $this->createMock(OrganizationMapper::class);
        $this->organizationMapper->method('find')->willReturnCallback(static function (int $id): Organization {
            $organization = new Organization();
            $organization->setId($id);
            $organization->setName('Org ' . $id);
            return $organization;
        });

        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->groupManager->method('getUserGroupIds')->willReturnCallback(
            fn (IUser $user): array => array_keys(array_filter($this->groups, static fn (array $uids): bool => in_array($user->getUID(), $uids, true))),
        );
        $this->groupManager->method('get')->willReturnCallback(
            fn (string $gid): ?IGroup => isset($this->groups[$gid]) ? $this->group($gid) : null,
        );
        $this->groupManager->method('createGroup')->willReturnCallback(function (string $gid): IGroup {
            $this->groups[$gid] = [];
            return $this->group($gid);
        });
        $this->groupManager->method('search')->willReturnCallback(
            fn (string $search): array => array_map(fn (string $gid): IGroup => $this->group($gid), array_filter(
                array_keys($this->groups),
                static fn (string $gid): bool => str_contains($gid, $search),
            )),
        );

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturnCallback(fn (string $uid): IUser => $this->user($uid));

        $this->service = new OrganizationGroupService(
            $this->userMapper,
            $this->organizationMapper,
            $this->groupManager,
            $userManager,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function user(string $uid): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    private function group(string $gid): IGroup
    {
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn($gid);
        $group->method('addUser')->willReturnCallback(function (IUser $user) use ($gid): void {
            $this->groups[$gid] = array_values(array_unique([...$this->groups[$gid], $user->getUID()]));
        });
        $group->method('removeUser')->willReturnCallback(function (IUser $user) use ($gid): void {
            $this->groups[$gid] = array_values(array_diff($this->groups[$gid], [$user->getUID()]));
        });
        $group->method('getUsers')->willReturnCallback(
            fn (): array => array_map(fn (string $uid): IUser => $this->user($uid), $this->groups[$gid]),
        );
        return $group;
    }

    public function testMemberJoinsBothGroupsWhichAreCreatedOnDemand(): void
    {
        $this->memberships['alice'] = ['organization_id' => 7, 'role' => 'member'];
        $this->groupManager->expects($this->exactly(2))->method('createGroup');

        $this->service->syncUser('alice');

        $this->assertSame(['alice'], $this->groups['organization-members']);
        $this->assertSame(['alice'], $this->groups['organization-members-7']);
    }

    public function testMovingOrganizationLeavesTheOldGroup(): void
    {
        $this->groups = ['organization-members' => ['alice'], 'organization-members-7' => ['alice'], 'proj-a' => ['alice']];
        $this->memberships['alice'] = ['organization_id' => 8, 'role' => 'member'];

        $this->service->syncUser('alice');

        $this->assertSame([], $this->groups['organization-members-7']);
        $this->assertSame(['alice'], $this->groups['organization-members-8']);
        $this->assertSame(['alice'], $this->groups['proj-a'], 'Other groups stay untouched');
    }

    public function testFormerMemberLeavesBothGroups(): void
    {
        $this->groups = ['organization-members' => ['alice'], 'organization-members-7' => ['alice']];

        $this->service->syncUser('alice');

        $this->assertSame([], $this->groups['organization-members']);
        $this->assertSame([], $this->groups['organization-members-7']);
    }

    public function testSyncAllBackfillsMembersAndDropsFormerOnes(): void
    {
        $this->organizationMapper->method('findAll')->willReturnCallback(static function (): array {
            $organization = new Organization();
            $organization->setId(7);
            return [$organization];
        });
        $this->memberships = ['alice' => ['organization_id' => 7, 'role' => 'member'], 'bob' => ['organization_id' => 7, 'role' => 'admin']];
        $this->groups = ['organization-members' => ['gone'], 'organization-members-7' => ['gone']];

        $this->assertSame(3, $this->service->syncAll());

        $this->assertEqualsCanonicalizing(['alice', 'bob'], $this->groups['organization-members']);
        $this->assertEqualsCanonicalizing(['alice', 'bob'], $this->groups['organization-members-7']);
    }
}
