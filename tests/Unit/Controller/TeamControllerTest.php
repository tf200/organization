<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Controller;

use OCA\Organization\Controller\TeamController;
use OCA\Organization\Db\UserMapper;
use OCA\Organization\Service\TeamService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class TeamControllerTest extends TestCase
{
    private TeamService $service;
    private UserMapper $users;
    private IGroupManager $groups;
    private IUserSession $session;
    private IUser $currentUser;
    private TeamController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(TeamService::class);
        $this->users = $this->createMock(UserMapper::class);
        $this->groups = $this->createMock(IGroupManager::class);
        $this->session = $this->createMock(IUserSession::class);
        $this->currentUser = $this->createMock(IUser::class);
        $this->currentUser->method('getUID')->willReturn('admin');
        $this->session->method('getUser')->willReturn($this->currentUser);
        $this->controller = new TeamController(
            'organization',
            $this->createMock(IRequest::class),
            $this->service,
            $this->users,
            $this->groups,
            $this->session,
        );
    }

    public function testGlobalAdminCanListAnyOrganization(): void
    {
        $this->groups->method('isAdmin')->with('admin')->willReturn(true);
        $this->service->expects(self::once())->method('list')->with(99)->willReturn([]);

        $this->controller->index(99);
    }

    public function testOrganizationAdminCannotListAnotherOrganization(): void
    {
        $this->groups->method('isAdmin')->willReturn(false);
        $this->users->method('getOrganizationMembership')->willReturn(['organization_id' => 1, 'role' => 'admin']);

        $this->expectException(OCSNotFoundException::class);
        $this->controller->index(2);
    }

    public function testOrganizationMemberCannotManageTeams(): void
    {
        $this->groups->method('isAdmin')->willReturn(false);
        $this->users->method('getOrganizationMembership')->willReturn(['organization_id' => 1, 'role' => 'member']);

        $this->expectException(OCSForbiddenException::class);
        $this->controller->index(1);
    }

    public function testOrganizationMemberCannotManageProjectAssignments(): void
    {
        $this->groups->method('isAdmin')->willReturn(false);
        $this->users->method('getOrganizationMembership')->willReturn(['organization_id' => 1, 'role' => 'member']);

        $this->expectException(OCSForbiddenException::class);
        $this->controller->projectTeams(1);
    }

    public function testGlobalAdminCanAssignProjectForAnyOrganization(): void
    {
        $this->groups->method('isAdmin')->with('admin')->willReturn(true);
        $this->service->expects(self::once())->method('assignProjectTeam')->with(99, 10, 4, 'admin')->willReturn([]);

        $this->controller->assignProjectTeam(99, 10, 4);
    }
}
