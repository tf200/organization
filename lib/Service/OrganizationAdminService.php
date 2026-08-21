<?php
declare(strict_types=1);

namespace OCA\Organization\Service;

use OCA\Organization\Db\OrganizationMapper;
use OCA\Organization\Db\UserMapper;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserManager;

class OrganizationAdminService
{
    public const ORGANIZATION_ADMINS_GROUP = 'organization-admins';
    public const PROJECT_ADMIN_GROUP_PREFIX = 'organization-project-admins-';

    public function __construct(
        private IUserManager $userManager,
        private IAppManager $appManager,
        private IGroupManager $groupManager,
        private OrganizationMapper $organizationMapper,
        private UserMapper $userMapper,
    ) {
    }

    /**
     * Create the organization admin account and assign it to the organization.
     */
    public function createOrganizationAdmin(
        int $organizationId,
        string $adminUserId,
        string $adminPassword,
        ?string $adminDisplayName,
        ?string $adminEmail,
    ): void {
        if ($this->userManager->userExists($adminUserId)) {
            throw new \InvalidArgumentException('Admin user ID already exists');
        }

        $user = $this->userManager->createUser($adminUserId, $adminPassword);
        if ($user === false) {
            throw new \RuntimeException('Failed to create organization admin user');
        }

        try {
            if ($adminDisplayName !== null && trim($adminDisplayName) !== '') {
                $user->setDisplayName(trim($adminDisplayName));
            }
            if ($adminEmail !== null && trim($adminEmail) !== '') {
                $user->setEMailAddress(trim($adminEmail));
            }

            $this->userMapper->addOrganizationToUser($adminUserId, $organizationId, 'admin');

            $orgAdminsGroup = $this->groupManager->get(self::ORGANIZATION_ADMINS_GROUP);
            if ($orgAdminsGroup === null) {
                $orgAdminsGroup = $this->groupManager->createGroup(self::ORGANIZATION_ADMINS_GROUP);
            }
            if ($orgAdminsGroup !== null) {
                $orgAdminsGroup->addUser($user);

                foreach ($this->userMapper->getOrganizationAdminUserIds() as $orgAdminUserId) {
                    $orgAdminUser = $this->userManager->get($orgAdminUserId);
                    if ($orgAdminUser !== null) {
                        $orgAdminsGroup->addUser($orgAdminUser);
                    }
                }

                $adminGroup = $this->groupManager->get('admin');
                $groups = [];
                if ($adminGroup !== null) {
                    $groups[] = $adminGroup;
                }
                $groups[] = $orgAdminsGroup;
                try {
                    $this->appManager->enableAppForGroups('organization', $groups);
                } catch (\Throwable $e) {
                }
            }

            $projectAdminsGroupId = self::PROJECT_ADMIN_GROUP_PREFIX . $organizationId;
            $projectAdminsGroup = $this->groupManager->get($projectAdminsGroupId)
                ?? $this->groupManager->createGroup($projectAdminsGroupId);
            if ($projectAdminsGroup !== null) {
                $projectAdminsGroup->addUser($user);
            }

            $organization = $this->organizationMapper->find($organizationId);
            if ($organization === null) {
                throw new \RuntimeException('Organization not found for admin assignment');
            }

            $organization->setAdminUid($adminUserId);
            $this->organizationMapper->update($organization);
        } catch (\Throwable $e) {
            $user->delete();
            throw $e;
        }
    }
}
