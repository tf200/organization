<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Service;

use OCA\Organization\Db\Team;
use OCA\Organization\Db\TeamMapper;
use OCA\Organization\Db\TeamMemberMapper;
use OCA\Organization\Db\ProjectTeamMapper;
use OCA\Organization\Db\UserMapper;
use OCA\Organization\Event\ProjectTeamChangedEvent;
use OCA\Organization\Event\TeamDeletedEvent;
use OCA\Organization\Event\TeamMemberAddedEvent;
use OCA\Organization\Event\TeamMemberRemovedEvent;
use OCA\Organization\Service\TeamService;
use OCP\AppFramework\OCS\OCSException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class TeamServiceTest extends TestCase
{
    private TeamMapper $teams;
    private TeamMemberMapper $members;
    private UserMapper $users;
    private IUserManager $userManager;
    private ProjectTeamMapper $projectTeams;
    /** @var Event[] */
    private array $events = [];
    private TeamService $service;

    protected function setUp(): void
    {
        $this->teams = $this->createMock(TeamMapper::class);
        $this->members = $this->createMock(TeamMemberMapper::class);
        $this->users = $this->createMock(UserMapper::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->projectTeams = $this->createMock(ProjectTeamMapper::class);
        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event): void {
            $this->events[] = $event;
        });
        $this->service = new TeamService($this->teams, $this->members, $this->users, $this->userManager, $this->projectTeams, $dispatcher);
    }

    public function testRejectsBlankName(): void
    {
        $this->expectException(OCSException::class);
        $this->service->create(1, '  ', null, 'admin');
    }

    public function testRejectsDuplicateNameWithinOrganization(): void
    {
        $existing = new Team();
        $existing->setId(7);
        $this->teams->expects(self::once())
            ->method('findByOrganizationAndName')
            ->with(1, 'Design')
            ->willReturn($existing);

        $this->expectException(OCSException::class);
        $this->service->create(1, 'Design', null, 'admin');
    }

    public function testMemberMustBelongToOrganization(): void
    {
        $team = $this->team(4);
        $this->teams->method('findByIdAndOrganization')->willReturn($team);
        $this->userManager->method('get')->willReturn($this->createMock(IUser::class));
        $this->users->method('getOrganizationMembership')->willReturn(['organization_id' => 2, 'role' => 'member']);

        $this->expectException(OCSException::class);
        $this->service->addMember(1, 4, 'alice');
    }

    public function testMemberCanBeAssignedToMultipleTeamsAndRepeatIsIdempotent(): void
    {
        $team = $this->team(4);
        $secondTeam = $this->team(5);
        $this->teams->method('findByIdAndOrganization')->willReturnOnConsecutiveCalls($team, $secondTeam, $team);
        $this->userManager->method('get')->willReturn($this->createMock(IUser::class));
        $this->users->method('getOrganizationMembership')->willReturn(['organization_id' => 1, 'role' => 'member']);
        $this->members->method('hasMember')->willReturnOnConsecutiveCalls(false, false, true);
        $addedTeams = [];
        $this->members->expects(self::exactly(2))->method('add')->willReturnCallback(
            static function (int $teamId, int $organizationId, string $userId) use (&$addedTeams): void {
                $addedTeams[] = [$teamId, $organizationId, $userId];
            },
        );
        $this->members->method('getUserIds')->willReturn([]);

        $this->service->addMember(1, 4, 'alice');
        $this->service->addMember(1, 5, 'alice');
        $this->service->addMember(1, 4, 'alice');
        self::assertSame([[4, 1, 'alice'], [5, 1, 'alice']], $addedTeams);
    }

    public function testRemovingOrganizationMemberClearsTeamMemberships(): void
    {
        $this->members->expects(self::once())
            ->method('removeUser')
            ->with('alice', 1);

        $this->service->removeOrganizationMember('alice', 1);
    }

    public function testAssigningProjectToTeamReturnsAssignedProject(): void
    {
        $team = $this->team(4);
        $this->projectTeams->method('findProject')->with(1, 10)->willReturn(['project_id' => 10, 'project_name' => 'Website']);
        $this->teams->expects(self::once())->method('findByIdAndOrganization')->with(4, 1)->willReturn($team);
        $this->projectTeams->expects(self::once())->method('assign')->with(1, 10, 4, 'admin', self::isType('string'));
        $this->projectTeams->expects(self::once())->method('findByOrganization')->with(1)->willReturn([
            ['projectId' => 10, 'projectName' => 'Website', 'team' => ['id' => 4, 'name' => 'Design']],
        ]);

        self::assertSame([['projectId' => 10, 'projectName' => 'Website', 'team' => ['id' => 4, 'name' => 'Design']]], $this->service->assignProjectTeam(1, 10, 4, 'admin'));
    }

    public function testUnassigningProjectRemovesItsAssignment(): void
    {
        $this->projectTeams->method('findProject')->willReturn(['project_id' => 10, 'project_name' => 'Website']);
        $this->projectTeams->expects(self::once())->method('unassign')->with(1, 10);
        $this->projectTeams->method('findByOrganization')->willReturn([]);

        self::assertSame([], $this->service->assignProjectTeam(1, 10, null, 'admin'));
    }

    public function testReassigningProjectReplacesItsTeam(): void
    {
        $firstTeam = $this->team(4);
        $secondTeam = $this->team(5);
        $this->projectTeams->method('findProject')->willReturn(['project_id' => 10, 'project_name' => 'Website']);
        $this->teams->method('findByIdAndOrganization')->willReturnOnConsecutiveCalls($firstTeam, $secondTeam);
        $this->projectTeams->expects(self::exactly(2))->method('assign')->with(1, 10, self::isType('int'), 'admin', self::isType('string'));
        $this->projectTeams->method('findByOrganization')->willReturn([]);

        $this->service->assignProjectTeam(1, 10, 4, 'admin');
        $this->service->assignProjectTeam(1, 10, 5, 'admin');
    }

    public function testProjectMustBelongToOrganization(): void
    {
        $this->projectTeams->method('findProject')->willReturn(null);
        $this->expectException(\OCP\AppFramework\OCS\OCSNotFoundException::class);

        $this->service->assignProjectTeam(1, 10, 4, 'admin');
    }

    public function testTeamMustBelongToOrganization(): void
    {
        $this->projectTeams->method('findProject')->willReturn(['project_id' => 10, 'project_name' => 'Website']);
        $this->teams->method('findByIdAndOrganization')->willReturn(null);
        $this->expectException(\OCP\AppFramework\OCS\OCSNotFoundException::class);

        $this->service->assignProjectTeam(1, 10, 99, 'admin');
    }

    public function testDeletingTeamUnassignsItsProjects(): void
    {
        $team = $this->team(4);
        $this->teams->method('findByIdAndOrganization')->willReturn($team);
        $this->projectTeams->expects(self::once())->method('removeForTeam')->with(1, 4);
        $this->members->expects(self::once())->method('removeTeam')->with(4, 1);
        $this->teams->expects(self::once())->method('delete')->with($team);

        $this->service->delete(1, 4);
    }

    public function testTeamChangesAreAnnounced(): void
    {
        $team = $this->team(4);
        $this->teams->method('findByIdAndOrganization')->willReturn($team);
        $this->userManager->method('get')->willReturn($this->createMock(IUser::class));
        $this->users->method('getOrganizationMembership')->willReturn(['organization_id' => 1, 'role' => 'member']);
        $this->members->method('hasMember')->willReturnOnConsecutiveCalls(false, true);
        $this->members->method('remove')->willReturn(1);
        $this->members->method('getUserIds')->willReturn([]);
        $this->projectTeams->method('findProject')->willReturn(['project_id' => 10, 'project_name' => 'Website']);
        $this->projectTeams->method('findTeamIdForProject')->willReturnOnConsecutiveCalls(3, 4);
        $this->projectTeams->method('findByOrganization')->willReturn([]);

        $this->service->addMember(1, 4, 'alice');
        $this->service->addMember(1, 4, 'alice');
        $this->service->removeMember(1, 4, ' alice ');
        $this->service->assignProjectTeam(1, 10, 4, 'admin');
        $this->service->assignProjectTeam(1, 10, 4, 'admin');
        $this->service->delete(1, 4);

        self::assertCount(4, $this->events);
        self::assertInstanceOf(TeamMemberAddedEvent::class, $this->events[0]);
        self::assertInstanceOf(TeamMemberRemovedEvent::class, $this->events[1]);
        self::assertSame('alice', $this->events[1]->getUserId());
        self::assertInstanceOf(ProjectTeamChangedEvent::class, $this->events[2]);
        self::assertSame([3, 4], [$this->events[2]->getPreviousTeamId(), $this->events[2]->getTeamId()]);
        self::assertInstanceOf(TeamDeletedEvent::class, $this->events[3]);
    }

    private function team(int $id): Team
    {
        $team = new Team();
        $team->setId($id);
        return $team;
    }
}
