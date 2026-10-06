<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCA\Organization\Db\OrganizationMapper;
use OCA\Organization\Db\UserMapper;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Mirrors organization_members into Nextcloud groups: one group per
 * organization, so user search can be limited to shared groups, and one group
 * of all members, which staff-only apps are limited to. Externals are in neither.
 */
class OrganizationGroupService
{
    public const MEMBERS_GROUP = 'organization-members';
    public const ORGANIZATION_GROUP_PREFIX = 'organization-members-';

    public function __construct(
        private UserMapper $userMapper,
        private OrganizationMapper $organizationMapper,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
    }

    public static function organizationGroupId(int $organizationId): string
    {
        return self::ORGANIZATION_GROUP_PREFIX . $organizationId;
    }

    /**
     * Puts the user in the groups of their organization and takes them out of
     * any other organization's group. A failure is logged, never thrown, so it
     * cannot undo the membership change that triggered it.
     */
    public function syncUser(string $userId): void
    {
        try {
            $user = $this->userManager->get($userId);
            if ($user === null) {
                return;
            }

            $organizationId = $this->userMapper->getOrganizationMembership($userId)['organization_id'] ?? null;
            $wanted = $organizationId === null ? [] : [self::MEMBERS_GROUP, self::organizationGroupId($organizationId)];

            foreach ($this->groupManager->getUserGroupIds($user) as $groupId) {
                if ($this->isManagedGroup($groupId) && !in_array($groupId, $wanted, true)) {
                    $this->groupManager->get($groupId)?->removeUser($user);
                }
            }

            foreach ($wanted as $groupId) {
                $this->ensureGroup($groupId, $organizationId)?->addUser($user);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync organization groups for a user', [
                'userId' => $userId,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Brings every member's groups in line and empties the groups of users who
     * are no longer members. Returns how many users were checked.
     */
    public function syncAll(): int
    {
        $userIds = [];
        foreach ($this->organizationMapper->findAll() as $organization) {
            foreach ($this->userMapper->getOrganizationMembers((int) $organization['id']) as $member) {
                $userIds[$member['user_uid']] = true;
            }
        }

        foreach ($this->groupManager->search(self::MEMBERS_GROUP) as $group) {
            if (!$this->isManagedGroup($group->getGID())) {
                continue;
            }
            foreach ($group->getUsers() as $user) {
                $userIds[$user->getUID()] = true;
            }
        }

        foreach (array_keys($userIds) as $userId) {
            $this->syncUser((string) $userId);
        }

        return count($userIds);
    }

    private function isManagedGroup(string $groupId): bool
    {
        return $groupId === self::MEMBERS_GROUP || str_starts_with($groupId, self::ORGANIZATION_GROUP_PREFIX);
    }

    private function ensureGroup(string $groupId, ?int $organizationId): ?IGroup
    {
        $group = $this->groupManager->get($groupId);
        if ($group !== null) {
            return $group;
        }

        $group = $this->groupManager->createGroup($groupId);
        $organization = $groupId === self::MEMBERS_GROUP || $organizationId === null ? null : $this->organizationMapper->find($organizationId);
        if ($group !== null && $organization !== null) {
            $group->setDisplayName('Organization: ' . $organization->getName());
        }

        return $group;
    }
}
