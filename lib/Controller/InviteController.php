<?php

declare(strict_types=1);

namespace OCA\Organization\Controller;

use OCA\Organization\Db\ExternalGrant;
use OCA\Organization\Db\OrganizationMapper;
use OCA\Organization\Service\ExternalCollaboratorService;
use OCA\Organization\Service\ExternalLifecycleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
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
        private ExternalLifecycleService $lifecycleService,
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
            return $this->renderDeadLink($token);
        }

        return $this->render($this->inviteParams($token, $invite));
    }

    /**
     * Asks the inviters for a new link. Whoever holds the old link never gets
     * the new one from here; it goes to the invited email address.
     */
    #[PublicPage]
    #[BruteForceProtection(action: 'organization_invite')]
    #[AnonRateLimit(limit: 5, period: 3600)]
    public function requestLink(string $token): TemplateResponse
    {
        $result = $this->lifecycleService->requestNewLink($token);
        if ($result === 'requested' || $result === 'already_requested') {
            return $this->render(['invalid' => true, 'deadLink' => 'requested']);
        }

        return $this->renderDeadLink($token);
    }

    private function renderDeadLink(string $token): TemplateResponse
    {
        $link = $this->externalService->describeDeadLink($token);
        $params = ['invalid' => true, 'deadLink' => $link['state']];
        if ($link['state'] === 'accepted') {
            $params['loginUrl'] = $this->urlGenerator->linkToRoute('core.login.showLoginForm', [
                'user' => $link['external']?->getEmail() ?? '',
                'redirect_url' => $this->urlGenerator->linkToRoute('projectcreatoraio.page.index'),
            ]);
        } elseif ($link['state'] === 'requestable') {
            $params['requestUrl'] = $this->urlGenerator->linkToRoute('organization.Invite.requestLink', ['token' => $token]);
        }

        $response = $this->render($params);
        if ($link['state'] === 'unknown') {
            $response->setStatus(Http::STATUS_NOT_FOUND);
            $response->throttle(['reason' => 'invalid invite token']);
        }
        return $response;
    }

    #[PublicPage]
    #[BruteForceProtection(action: 'organization_invite')]
    public function accept(string $token, string $password = '', string $passwordConfirm = '', bool $terms = false): TemplateResponse|RedirectResponse
    {
        $invite = $this->externalService->findOpenInvite($token);
        if ($invite === null) {
            return $this->renderDeadLink($token);
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
                return $this->renderDeadLink($token);
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
        return new TemplateResponse($this->appName, 'invite', $params + [
            'error' => null,
            'deadLink' => null,
            'loginUrl' => null,
            'requestUrl' => null,
        ], TemplateResponse::RENDER_AS_GUEST);
    }
}
