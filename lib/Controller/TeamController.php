<?php

declare(strict_types=1);

namespace OCA\Organization\Controller;

use OCA\Organization\Db\UserMapper;
use OCA\Organization\Service\TeamService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class TeamController extends OCSController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private TeamService $teamService,
        private UserMapper $userMapper,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function index(int $organizationId): DataResponse
    {
        $this->assertCanManage($organizationId);
        return new DataResponse(['teams' => $this->teamService->list($organizationId)]);
    }

    #[NoAdminRequired]
    public function create(int $organizationId, string $name, ?string $description = null, float $fte = 1.0, float $projectsPerFte = 1.0): DataResponse
    {
        $this->assertCanManage($organizationId);
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new OCSForbiddenException('Authentication required');
        }
        return new DataResponse(['team' => $this->teamService->create($organizationId, $name, $description, $fte, $projectsPerFte, $user->getUID())]);
    }

    #[NoAdminRequired]
    public function update(int $organizationId, int $teamId, string $name, ?string $description = null, float $fte = 1.0, float $projectsPerFte = 1.0): DataResponse
    {
        $this->assertCanManage($organizationId);
        return new DataResponse(['team' => $this->teamService->update($organizationId, $teamId, $name, $description, $fte, $projectsPerFte)]);
    }

    #[NoAdminRequired]
    public function destroy(int $organizationId, int $teamId): DataResponse
    {
        $this->assertCanManage($organizationId);
        $this->teamService->delete($organizationId, $teamId);
        return new DataResponse([]);
    }

    #[NoAdminRequired]
    public function addMember(int $organizationId, int $teamId, string $userId): DataResponse
    {
        $this->assertCanManage($organizationId);
        return new DataResponse(['team' => $this->teamService->addMember($organizationId, $teamId, $userId)]);
    }

    #[NoAdminRequired]
    public function removeMember(int $organizationId, int $teamId, string $userId): DataResponse
    {
        $this->assertCanManage($organizationId);
        return new DataResponse(['team' => $this->teamService->removeMember($organizationId, $teamId, $userId)]);
    }

    #[NoAdminRequired]
    public function projectTeams(int $organizationId): DataResponse
    {
        $this->assertCanManage($organizationId);
        return new DataResponse(['projectTeams' => $this->teamService->listProjectTeams($organizationId)]);
    }

    #[NoAdminRequired]
    public function assignProjectTeam(int $organizationId, int $projectId, ?int $teamId = null): DataResponse
    {
        $this->assertCanManage($organizationId);
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new OCSForbiddenException('Authentication required');
        }
        return new DataResponse(['projectTeams' => $this->teamService->assignProjectTeam($organizationId, $projectId, $teamId, $user->getUID())]);
    }

    private function assertCanManage(int $organizationId): void
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new OCSForbiddenException('Authentication required');
        }
        if ($this->groupManager->isAdmin($user->getUID())) {
            return;
        }
        $membership = $this->userMapper->getOrganizationMembership($user->getUID());
        if ($membership === null || $membership['role'] !== 'admin') {
            throw new OCSForbiddenException('Only organization admins can access this resource');
        }
        if ($membership['organization_id'] !== $organizationId) {
            throw new OCSNotFoundException('Organization does not exist');
        }
    }
}
