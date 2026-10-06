<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCA\Organization\Db\ExternalGrant;
use OCA\Organization\Db\UserMapper;

use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Who may talk to whom. Members talk within their organization; an external
 * collaborator talks with the people of the projects they hold a usable grant
 * on, which are the project groups they were added to, and with the admins of
 * the organizations that granted it.
 */
class TalkOrganizationPolicyService
{
    /** @var array<string,array{organizationIds: int[], groupIds: string[]}|null> */
    private array $externalAccess = [];

    public function __construct(
        private UserMapper $userMapper,
        private IGroupManager $groupManager,
        private ?ExternalCollaboratorService $externals = null,
        private ?IUserManager $userManager = null,
    ) {
    }

    public function isGlobalAdmin(?string $userId): bool
    {
        return $userId !== null && $userId !== '' && $this->groupManager->isAdmin($userId);
    }

    public function canUserUseTalk(?string $userId): bool
    {
        if ($userId === null || $userId === '') {
            return false;
        }

        if ($this->isGlobalAdmin($userId)) {
            return true;
        }

        return $this->userMapper->getOrganizationMembership($userId) !== null
            || $this->getExternalAccess($userId) !== null;
    }

    public function getOrganizationIdForUser(?string $userId): ?int
    {
        if ($userId === null || $userId === '' || $this->isGlobalAdmin($userId)) {
            return null;
        }

        return $this->userMapper->getOrganizationMembership($userId)['organization_id'] ?? null;
    }

    /**
     * @param string[] $userIds
     * @return array<string,?int>
     */
    public function getOrganizationIdsForUsers(array $userIds): array
    {
        $organizationIds = [];
        $membershipCandidateIds = [];

        foreach (array_values(array_unique($userIds)) as $userId) {
            if ($userId === '') {
                continue;
            }

            if ($this->isGlobalAdmin($userId)) {
                $organizationIds[$userId] = null;
                continue;
            }

            $membershipCandidateIds[] = $userId;
            $organizationIds[$userId] = null;
        }

        $memberships = $this->userMapper->getOrganizationMemberships($membershipCandidateIds);
        foreach ($memberships as $userId => $membership) {
            $organizationIds[$userId] = $membership['organization_id'];
        }

        return $organizationIds;
    }

    public function canUsersCommunicate(?string $firstUserId, ?string $secondUserId): bool
    {
        if ($firstUserId === null || $firstUserId === '' || $secondUserId === null || $secondUserId === '') {
            return false;
        }

        if ($this->isGlobalAdmin($firstUserId) || $this->isGlobalAdmin($secondUserId)) {
            return true;
        }

        $organizationIds = $this->getOrganizationIdsForUsers([$firstUserId, $secondUserId]);
        $firstOrganizationId = $organizationIds[$firstUserId] ?? null;
        $secondOrganizationId = $organizationIds[$secondUserId] ?? null;

        if ($firstOrganizationId !== null && $firstOrganizationId === $secondOrganizationId) {
            return true;
        }

        return $this->shareProject($firstUserId, $secondOrganizationId !== null, $secondUserId)
            || $this->shareProject($secondUserId, $firstOrganizationId !== null, $firstUserId);
    }

    /**
     * True when $externalUserId is a usable external and $otherUserId is an
     * admin of an organization that granted them access, or is a member or
     * usable external in one of the external's project groups.
     */
    private function shareProject(string $externalUserId, bool $otherIsMember, string $otherUserId): bool
    {
        $access = $this->getExternalAccess($externalUserId);
        if ($access === null) {
            return false;
        }

        if ($otherIsMember) {
            $membership = $this->userMapper->getOrganizationMembership($otherUserId);
            if ($membership !== null && $membership['role'] === 'admin'
                && in_array($membership['organization_id'], $access['organizationIds'], true)) {
                return true;
            }
        } elseif ($this->getExternalAccess($otherUserId) === null) {
            return false;
        }

        foreach ($access['groupIds'] as $groupId) {
            if ($this->groupManager->isInGroup($otherUserId, $groupId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The organizations that granted an external access and the groups they
     * share with project co-members, or null when the user is not an external
     * or has no usable grant. Revoking a grant removes the external from that
     * project's group.
     *
     * @return array{organizationIds: int[], groupIds: string[]}|null
     */
    private function getExternalAccess(string $userId): ?array
    {
        if (array_key_exists($userId, $this->externalAccess)) {
            return $this->externalAccess[$userId];
        }

        $access = null;
        $grants = $this->externals?->getUsableGrants($userId) ?? [];
        $user = $grants === [] ? null : $this->userManager?->get($userId);
        if ($user !== null) {
            $access = [
                'organizationIds' => array_values(array_unique(array_map(
                    static fn (ExternalGrant $grant): int => $grant->getOrganizationId(),
                    $grants,
                ))),
                'groupIds' => array_values(array_diff(
                    $this->groupManager->getUserGroupIds($user),
                    [ExternalCollaboratorService::EXTERNALS_GROUP],
                )),
            ];
        }

        return $this->externalAccess[$userId] = $access;
    }

    /**
     * @param string[] $candidateUserIds
     * @return string[]
     */
    public function filterReachableUserIds(?string $requestUserId, array $candidateUserIds): array
    {
        $candidateUserIds = array_values(array_unique(array_filter(array_map('trim', $candidateUserIds), static fn (string $userId): bool => $userId !== '')));
        if ($candidateUserIds === []) {
            return [];
        }

        if ($this->isGlobalAdmin($requestUserId)) {
            return $candidateUserIds;
        }

        if (!$this->canUserUseTalk($requestUserId)) {
            return [];
        }

        $reachableUserIds = [];
        foreach ($candidateUserIds as $candidateUserId) {
            if ($this->canUsersCommunicate($requestUserId, $candidateUserId)) {
                $reachableUserIds[] = $candidateUserId;
            }
        }

        return $reachableUserIds;
    }

    /**
     * @param string[] $roomUserIds
     */
    public function canUserAccessRoom(?string $requestUserId, array $roomUserIds): bool
    {
        if ($this->isGlobalAdmin($requestUserId)) {
            return true;
        }

        if (!$this->canUserUseTalk($requestUserId)) {
            return false;
        }

        $roomUserIds = array_values(array_unique(array_filter(array_map('trim', $roomUserIds), static fn (string $userId): bool => $userId !== '')));
        foreach ($roomUserIds as $roomUserId) {
            if (!$this->canUsersCommunicate($requestUserId, $roomUserId)) {
                return false;
            }
        }

        return true;
    }
}
