<?php

declare(strict_types=1);

namespace OCA\Organization\Listener;

use OCA\Organization\Service\ExternalCollaboratorService;
use OCA\Organization\Service\TalkOrganizationPolicyService;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\Share\IShare;

/**
 * External collaborators may share only inside their projects: to Deck cards,
 * to their conversations (which the Talk guard already limits), to project
 * co-members and to their project groups. Public links, email and federated
 * shares are refused. Core's group exclusion cannot do this, because it only
 * blocks users whose every group is excluded.
 *
 * @template-implements IEventListener<Event>
 */
class ExternalShareGuardListener implements IEventListener
{
    public const ERROR = 'External collaborators can only share within their projects.';

    public function __construct(
        private ExternalCollaboratorService $externals,
        private TalkOrganizationPolicyService $policyService,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!$event instanceof BeforeShareCreatedEvent) {
            return;
        }

        $share = $event->getShare();
        $sharedBy = (string) $share->getSharedBy();
        if ($sharedBy === '' || !$this->externals->isExternal($sharedBy)) {
            return;
        }

        if (!$this->isAllowed($sharedBy, $share)) {
            $event->setError(self::ERROR);
            $event->stopPropagation();
        }
    }

    private function isAllowed(string $sharedBy, IShare $share): bool
    {
        $recipient = (string) $share->getSharedWith();

        return match ($share->getShareType()) {
            IShare::TYPE_DECK, IShare::TYPE_ROOM => true,
            IShare::TYPE_USER => $this->policyService->canUsersCommunicate($sharedBy, $recipient),
            IShare::TYPE_GROUP => $this->isOwnProjectGroup($sharedBy, $recipient),
            default => false,
        };
    }

    private function isOwnProjectGroup(string $userId, string $groupId): bool
    {
        if ($groupId === '' || $groupId === ExternalCollaboratorService::EXTERNALS_GROUP) {
            return false;
        }

        $user = $this->userManager->get($userId);
        return $user !== null
            && $this->externals->getUsableGrants($userId) !== []
            && in_array($groupId, $this->groupManager->getUserGroupIds($user), true);
    }
}
