<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Listener;

use OCA\Organization\Db\ExternalGrant;
use OCA\Organization\Listener\ExternalShareGuardListener;
use OCA\Organization\Service\ExternalCollaboratorService;
use OCA\Organization\Service\TalkOrganizationPolicyService;

use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\Share\IShare;

use PHPUnit\Framework\TestCase;

class ExternalShareGuardListenerTest extends TestCase
{
    private ExternalShareGuardListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $externals = $this->createMock(ExternalCollaboratorService::class);
        $externals->method('isExternal')->willReturnCallback(static fn (string $uid): bool => $uid === 'ext');
        $externals->method('getUsableGrants')->willReturnCallback(
            static fn (string $uid): array => $uid === 'ext' ? [new ExternalGrant()] : [],
        );

        $policy = $this->createMock(TalkOrganizationPolicyService::class);
        $policy->method('canUsersCommunicate')->willReturnCallback(
            static fn (string $first, string $second): bool => $second === 'alice',
        );

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('getUserGroupIds')->willReturn(['externals', 'proj-a']);

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn($this->createMock(IUser::class));

        $this->listener = new ExternalShareGuardListener($externals, $policy, $groupManager, $userManager);
    }

    private function share(string $sharedBy, int $type, string $sharedWith = ''): BeforeShareCreatedEvent
    {
        $share = $this->createMock(IShare::class);
        $share->method('getSharedBy')->willReturn($sharedBy);
        $share->method('getShareType')->willReturn($type);
        $share->method('getSharedWith')->willReturn($sharedWith);

        $event = new BeforeShareCreatedEvent($share);
        $this->listener->handle($event);
        return $event;
    }

    public static function externalShares(): array
    {
        return [
            'to a Deck card' => [IShare::TYPE_DECK, '1', true],
            'to a conversation' => [IShare::TYPE_ROOM, 'token', true],
            'to a project co-member' => [IShare::TYPE_USER, 'alice', true],
            'to someone outside the project' => [IShare::TYPE_USER, 'bob', false],
            'to their project group' => [IShare::TYPE_GROUP, 'proj-a', true],
            'to another group' => [IShare::TYPE_GROUP, 'proj-b', false],
            'to the externals group' => [IShare::TYPE_GROUP, 'externals', false],
            'as a public link' => [IShare::TYPE_LINK, '', false],
            'by email' => [IShare::TYPE_EMAIL, 'someone@example.com', false],
            'to a federated user' => [IShare::TYPE_REMOTE, 'user@other.example', false],
        ];
    }

    /** @dataProvider externalShares */
    public function testExternalSharesStayInsideProjects(int $type, string $sharedWith, bool $allowed): void
    {
        $event = $this->share('ext', $type, $sharedWith);

        $this->assertSame($allowed ? null : ExternalShareGuardListener::ERROR, $event->getError());
        $this->assertSame(!$allowed, $event->isPropagationStopped());
    }

    public function testMembersAreNotChecked(): void
    {
        $event = $this->share('alice', IShare::TYPE_LINK);

        $this->assertNull($event->getError());
    }
}
