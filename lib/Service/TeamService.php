<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCA\Organization\Db\Team;
use OCA\Organization\Db\TeamMapper;
use OCA\Organization\Db\TeamMemberMapper;
use OCA\Organization\Db\ProjectTeamMapper;
use OCA\Organization\Db\UserMapper;
use OCA\Organization\Event\ProjectTeamChangedEvent;
use OCA\Organization\Event\TeamDeletedEvent;
use OCA\Organization\Event\TeamMemberAddedEvent;
use OCA\Organization\Event\TeamMemberRemovedEvent;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserManager;

class TeamService
{
    public function __construct(
        private TeamMapper $teamMapper,
        private TeamMemberMapper $memberMapper,
        private UserMapper $userMapper,
        private IUserManager $userManager,
        private ProjectTeamMapper $projectTeamMapper,
        private ?IEventDispatcher $eventDispatcher = null,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function list(int $organizationId): array
    {
        return array_map(fn (Team $team): array => $this->payload($team, $organizationId), $this->teamMapper->findByOrganization($organizationId));
    }

    public function create(int $organizationId, string $name, ?string $description, string $createdBy): array
    {
        $this->validate($name);
        $this->assertNameAvailable($organizationId, $name);
        $now = gmdate('Y-m-d H:i:s');
        $team = new Team();
        $team->setOrganizationId($organizationId);
        $team->setName(trim($name));
        $team->setDescription($description !== null && trim($description) !== '' ? trim($description) : null);
        $team->setCreatedBy($createdBy);
        $team->setCreatedAt($now);
        $team->setUpdatedAt($now);
        return $this->payload($this->teamMapper->insert($team), $organizationId);
    }

    public function update(int $organizationId, int $teamId, string $name, ?string $description): array
    {
        $this->validate($name);
        $team = $this->get($organizationId, $teamId);
        $this->assertNameAvailable($organizationId, $name, $teamId);
        $team->setName(trim($name));
        $team->setDescription($description !== null && trim($description) !== '' ? trim($description) : null);
        $team->setUpdatedAt(gmdate('Y-m-d H:i:s'));
        return $this->payload($this->teamMapper->update($team), $organizationId);
    }

    public function delete(int $organizationId, int $teamId): void
    {
        $team = $this->get($organizationId, $teamId);
        $this->memberMapper->removeTeam($team->getId(), $organizationId);
        $this->projectTeamMapper->removeForTeam($organizationId, $team->getId());
        $this->teamMapper->delete($team);
        $this->eventDispatcher?->dispatchTyped(new TeamDeletedEvent($organizationId, $teamId));
    }

    /** @return array<int,array<string,mixed>> */
    public function listProjectTeams(int $organizationId): array
    {
        return $this->projectTeamMapper->findByOrganization($organizationId);
    }

    /** @return array<int,array<string,mixed>> */
    public function assignProjectTeam(int $organizationId, int $projectId, ?int $teamId, string $createdBy): array
    {
        $project = $this->projectTeamMapper->findProject($organizationId, $projectId);
        if ($project === null) {
            throw new OCSNotFoundException('Project does not exist in this organization');
        }
        $previousTeamId = $this->projectTeamMapper->findTeamIdForProject($organizationId, $projectId);
        if ($teamId !== null) {
            $this->get($organizationId, $teamId);
            $this->projectTeamMapper->assign($organizationId, $projectId, $teamId, $createdBy, gmdate('Y-m-d H:i:s'));
        } else {
            $this->projectTeamMapper->unassign($organizationId, $projectId);
        }
        if ($previousTeamId !== $teamId) {
            $this->eventDispatcher?->dispatchTyped(new ProjectTeamChangedEvent($organizationId, $projectId, $previousTeamId, $teamId));
        }
        return $this->projectTeamMapper->findByOrganization($organizationId);
    }

    public function removeProjectTeam(int $projectId): void
    {
        $this->projectTeamMapper->removeForProject($projectId);
    }

    /** @return array<string,mixed> */
    public function addMember(int $organizationId, int $teamId, string $userId): array
    {
        $team = $this->get($organizationId, $teamId);
        $userId = trim($userId);
        if ($userId === '' || $this->userManager->get($userId) === null) {
            throw new OCSNotFoundException('User does not exist');
        }
        $membership = $this->userMapper->getOrganizationMembership($userId);
        if ($membership === null || $membership['organization_id'] !== $organizationId) {
            throw new OCSException('User must belong to this organization', 104);
        }
        if (!$this->memberMapper->hasMember($team->getId(), $organizationId, $userId)) {
            $this->memberMapper->add($team->getId(), $organizationId, $userId);
            $this->eventDispatcher?->dispatchTyped(new TeamMemberAddedEvent($organizationId, $team->getId(), $userId));
        }
        return $this->payload($team, $organizationId);
    }

    /** @return array<string,mixed> */
    public function removeMember(int $organizationId, int $teamId, string $userId): array
    {
        $team = $this->get($organizationId, $teamId);
        $userId = trim($userId);
        if ($this->memberMapper->remove($team->getId(), $organizationId, $userId) === 0) {
            throw new OCSNotFoundException('Team member not found');
        }
        $this->eventDispatcher?->dispatchTyped(new TeamMemberRemovedEvent($organizationId, $team->getId(), $userId));
        return $this->payload($team, $organizationId);
    }

    public function removeOrganizationMember(string $userId, int $organizationId): void
    {
        $this->memberMapper->removeUser($userId, $organizationId);
    }

    private function get(int $organizationId, int $teamId): Team
    {
        $team = $this->teamMapper->findByIdAndOrganization($teamId, $organizationId);
        if ($team === null) {
            throw new OCSNotFoundException('Team does not exist');
        }
        return $team;
    }

    private function validate(string $name): void
    {
        if (trim($name) === '') {
            throw new OCSException('Team name is required', 104);
        }
    }

    private function assertNameAvailable(int $organizationId, string $name, ?int $teamId = null): void
    {
        $existing = $this->teamMapper->findByOrganizationAndName($organizationId, trim($name));
        if ($existing !== null && $existing->getId() !== $teamId) {
            throw new OCSException('A team with this name already exists in the organization', 104);
        }
    }

    /** @return array<string,mixed> */
    private function payload(Team $team, int $organizationId): array
    {
        $data = $team->jsonSerialize();
        $data['members'] = array_map(function (string $uid): array {
            $user = $this->userManager->get($uid);
            return ['uid' => $uid, 'displayName' => $user?->getDisplayName() ?? $uid, 'email' => $user?->getEMailAddress()];
        }, $this->memberMapper->getUserIds($team->getId(), $organizationId));
        $data['memberCount'] = count($data['members']);
        return $data;
    }
}
