<?php

declare(strict_types=1);

namespace OCA\Organization\Controller;

use OCA\Organization\Db\UserMapper;
use OCA\Organization\Service\ExternalCollaboratorService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
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
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function index(int $organizationId): DataResponse
    {
        $this->assertCanManage($organizationId);
        return new DataResponse($this->externals->listForOrganization($organizationId));
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
