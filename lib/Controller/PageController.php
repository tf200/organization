<?php
declare(strict_types=1);

namespace OCA\Organization\Controller;

use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\IGroupManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IInitialState $initialState,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse|NotFoundResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new NotFoundResponse();
        }

        // Superadmin only. This app is the administration surface that sits
        // alongside superadminpage, and both the Plans and Trial-defaults
        // controllers are Nextcloud-admin-only at the HTTP layer anyway — an
        // organization admin who reached this page got two tabs that could
        // only ever answer "Logged in account must be an admin".
        if (!$this->groupManager->isAdmin($user->getUID())) {
            return new NotFoundResponse();
        }

        $this->initialState->provideInitialState('settings', [
            'appId' => $this->appName,
        ]);

        Util::addScript($this->appName, 'organization-main');
        Util::addStyle($this->appName, 'organization-main');

        return new TemplateResponse($this->appName, 'index');
    }
}
