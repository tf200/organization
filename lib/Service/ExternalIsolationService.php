<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCP\App\IAppManager;
use OCP\IAppConfig;

/**
 * The instance settings that keep external collaborators inside their
 * projects. User search is limited to people who share a group, so members
 * find their organization and externals find their project co-members, and
 * staff apps are limited to organization members.
 */
class ExternalIsolationService
{
    /** Apps externals have no use for; Files, Deck, Talk and Whiteboard stay open. */
    public const STAFF_APPS = ['mail', 'calendar', 'contacts', 'tasks'];

    /** Groups that span organizations and must not widen user search. */
    public const SEARCH_EXCLUDED_GROUPS = [
        ExternalCollaboratorService::EXTERNALS_GROUP,
        OrganizationGroupService::MEMBERS_GROUP,
        OrganizationAdminService::ORGANIZATION_ADMINS_GROUP,
    ];

    public function __construct(
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private OrganizationGroupService $organizationGroupService,
    ) {
    }

    /**
     * Describes what is not yet in place, one line per change.
     *
     * @return string[]
     */
    public function pendingChanges(): array
    {
        $changes = [];
        if ($this->appConfig->getValueString('core', 'shareapi_restrict_user_enumeration_to_group', 'no') !== 'yes') {
            $changes[] = 'Limit user search to people who share a group';
        }

        $missing = array_diff(self::SEARCH_EXCLUDED_GROUPS, $this->searchExcludedGroups());
        if ($missing !== []) {
            $changes[] = 'Leave ' . implode(', ', $missing) . ' out of user search';
        }

        foreach ($this->openStaffApps() as $appId) {
            $changes[] = 'Limit ' . $appId . ' to organization members and admins';
        }

        return $changes;
    }

    /**
     * Applies the settings. Group membership is synced first, so no member
     * loses search results or a staff app on the way.
     *
     * @return string[] the changes made
     */
    public function apply(): array
    {
        $changes = $this->pendingChanges();
        $this->organizationGroupService->syncAll();

        $this->appConfig->setValueString('core', 'shareapi_restrict_user_enumeration_to_group', 'yes');
        $this->appConfig->setValueString(
            'core',
            'shareapi_only_share_with_group_members_exclude_group_list',
            json_encode(array_values(array_unique(array_merge($this->searchExcludedGroups(), self::SEARCH_EXCLUDED_GROUPS)))),
        );

        foreach ($this->openStaffApps() as $appId) {
            $this->appManager->enableAppForGroups($appId, [OrganizationGroupService::MEMBERS_GROUP, 'admin']);
        }

        return $changes;
    }

    /**
     * @return string[]
     */
    private function searchExcludedGroups(): array
    {
        $groups = json_decode($this->appConfig->getValueString('core', 'shareapi_only_share_with_group_members_exclude_group_list', '[]'), true);
        return is_array($groups) ? array_values(array_filter($groups, 'is_string')) : [];
    }

    /**
     * Staff apps that are enabled for everyone. Apps already limited to groups
     * are left as an administrator set them.
     *
     * @return string[]
     */
    private function openStaffApps(): array
    {
        return array_values(array_filter(
            self::STAFF_APPS,
            fn (string $appId): bool => $this->appManager->isEnabledForAnyone($appId)
                && $this->appManager->getAppRestriction($appId) === [],
        ));
    }
}
