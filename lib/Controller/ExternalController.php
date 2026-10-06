<?php

declare(strict_types=1);

namespace OCA\Organization\Controller;

use OCA\Organization\Db\ExternalMapper;
use OCA\Organization\Db\UserMapper;
use OCA\Organization\Service\ExternalAuditService;
use OCA\Organization\Service\ExternalCollaboratorService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * The external collaborators of an organization, for its admins. Inviting
 * and revoking happen per project, in the project app.
 */
class ExternalController extends OCSController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private ExternalCollaboratorService $externals,
        private UserMapper $userMapper,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private ExternalAuditService $audit,
        private ExternalMapper $externalMapper,
        private IUserManager $userManager,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function index(int $organizationId): DataResponse
    {
        $this->assertCanManage($organizationId);
        return new DataResponse($this->externals->listForOrganization($organizationId));
    }

    /**
     * The latest audit entries of the organization's externals, with names
     * filled in. A deleted account keeps the email it was invited with.
     */
    #[NoAdminRequired]
    public function activity(int $organizationId, int $limit = 100): DataResponse
    {
        $this->assertCanManage($organizationId);
        $entries = $this->audit->listForOrganization($organizationId, max(1, min($limit, 500)));

        $emails = [];
        foreach ($entries as $entry) {
            $email = $entry->getDetailMap()['email'] ?? null;
            if (is_string($email)) {
                $emails[$entry->getUserUid()] ??= $email;
            }
        }

        $names = [];
        $projects = [];
        $rows = [];
        foreach ($entries as $entry) {
            $userUid = $entry->getUserUid();
            if (!array_key_exists($userUid, $names)) {
                $names[$userUid] = $this->externalMapper->findByUserUid($userUid)?->getDisplayName()
                    ?? $emails[$userUid]
                    ?? $userUid;
            }
            $actorUid = $entry->getActorUid();
            if ($actorUid !== null && !array_key_exists($actorUid, $names)) {
                $names[$actorUid] = $this->userManager->getDisplayName($actorUid) ?? $actorUid;
            }
            $projectId = $entry->getProjectId();
            if ($projectId !== null && !array_key_exists($projectId, $projects)) {
                $projects[$projectId] = $this->externals->projectName($projectId);
            }

            $rows[] = $entry->jsonSerialize() + [
                'displayName' => $names[$userUid],
                'actorName' => $actorUid === null ? null : $names[$actorUid],
                'projectName' => $projectId === null ? null : $projects[$projectId],
            ];
        }

        return new DataResponse(['entries' => $rows]);
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
