<?php

declare(strict_types=1);

namespace OCA\Organization\Controller;

use OCA\Organization\Db\ExternalGrant;
use OCA\Organization\Db\OrganizationMapper;
use OCA\Organization\Service\ExternalCollaboratorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;

/**
 * The public page where an invited external collaborator sets a password.
 */
class InviteController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private ExternalCollaboratorService $externalService,
        private OrganizationMapper $organizationMapper,
        private IUserManager $userManager,
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct($appName, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: 'organization_invite')]
    public function show(string $token): TemplateResponse
    {
        $invite = $this->externalService->findOpenInvite($token);
        if ($invite === null) {
            $response = $this->render(['invalid' => true]);
            $response->setStatus(Http::STATUS_NOT_FOUND);
            $response->throttle(['reason' => 'invalid invite token']);
            return $response;
        }

        return $this->render($this->inviteParams($token, $invite));
    }

    #[PublicPage]
    #[BruteForceProtection(action: 'organization_invite')]
    public function accept(string $token, string $password = '', string $passwordConfirm = '', bool $terms = false): TemplateResponse|RedirectResponse
    {
        $invite = $this->externalService->findOpenInvite($token);
        if ($invite === null) {
            $response = $this->render(['invalid' => true]);
            $response->setStatus(Http::STATUS_NOT_FOUND);
            $response->throttle(['reason' => 'invalid invite token']);
            return $response;
        }

        $error = null;
        if ($password === '' || $password !== $passwordConfirm) {
            $error = 'The two passwords do not match.';
        } elseif (!$terms) {
            $error = 'Please accept the terms to continue.';
        }

        if ($error === null) {
            try {
                $external = $this->externalService->acceptInvite($token, $password);
                return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm', [
                    'user' => $external->getEmail(),
                    'redirect_url' => $this->urlGenerator->linkToRoute('projectcreatoraio.page.index'),
                ]));
            } catch (OCSBadRequestException $e) {
                $error = $e->getMessage();
            } catch (OCSNotFoundException) {
                return $this->render(['invalid' => true]);
            }
        }

        $response = $this->render($this->inviteParams($token, $invite) + ['error' => $error]);
        $response->setStatus(Http::STATUS_BAD_REQUEST);
        return $response;
    }

    /**
     * @param array{external: \OCA\Organization\Db\External, grants: ExternalGrant[]} $invite
     */
    private function inviteParams(string $token, array $invite): array
    {
        $projects = [];
        foreach ($invite['grants'] as $grant) {
            $projects[] = [
                'name' => $this->externalService->projectName($grant->getProjectId()),
                'organization' => $this->organizationMapper->find($grant->getOrganizationId())?->getName() ?? '',
                'inviter' => $this->userManager->getDisplayName($grant->getInvitedBy()) ?? $grant->getInvitedBy(),
            ];
        }

        return [
            'invalid' => false,
            'token' => $token,
            'displayName' => $invite['external']->getDisplayName(),
            'email' => $invite['external']->getEmail(),
            'projects' => $projects,
            'acceptUrl' => $this->urlGenerator->linkToRoute('organization.Invite.accept', ['token' => $token]),
        ];
    }

    private function render(array $params): TemplateResponse
    {
        return new TemplateResponse($this->appName, 'invite', $params + ['error' => null], TemplateResponse::RENDER_AS_GUEST);
    }
}
