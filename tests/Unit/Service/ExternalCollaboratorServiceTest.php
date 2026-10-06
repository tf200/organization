<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Service;

use OCA\Organization\Db\External;
use OCA\Organization\Db\ExternalGrant;
use OCA\Organization\Db\ExternalGrantMapper;
use OCA\Organization\Db\ExternalInvite;
use OCA\Organization\Db\ExternalInviteMapper;
use OCA\Organization\Db\ExternalMapper;
use OCA\Organization\Db\OrganizationMapper;
use OCA\Organization\Db\Plan;
use OCA\Organization\Db\PlanMapper;
use OCA\Organization\Db\Subscription;
use OCA\Organization\Db\SubscriptionMapper;
use OCA\Organization\Db\UserMapper;
use OCA\Organization\Event\ExternalGrantActivatedEvent;
use OCA\Organization\Event\ExternalGrantRevokedEvent;
use OCA\Organization\Service\ExternalCollaboratorService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExternalCollaboratorServiceTest extends TestCase
{
    private const ORG = 5;
    private const PROJECT = 39;
    private const NOW = '2026-10-05 12:00:00';

    private ExternalMapper $externals;
    private ExternalGrantMapper $grants;
    private ExternalInviteMapper $invites;
    private UserMapper $members;
    private SubscriptionMapper $subscriptions;
    private PlanMapper $plans;
    private IUserManager $userManager;
    private IGroupManager $groupManager;
    private IMailer $mailer;
    /** @var Event[] */
    private array $events = [];
    private Subscription $subscription;
    private Plan $plan;
    private ExternalCollaboratorService $service;

    protected function setUp(): void
    {
        $this->externals = $this->createMock(ExternalMapper::class);
        $this->grants = $this->createMock(ExternalGrantMapper::class);
        $this->invites = $this->createMock(ExternalInviteMapper::class);
        $this->members = $this->createMock(UserMapper::class);
        $this->subscriptions = $this->createMock(SubscriptionMapper::class);
        $this->plans = $this->createMock(PlanMapper::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->mailer = $this->createMock(IMailer::class);

        $this->subscription = new Subscription();
        $this->subscription->setPlanId(3);
        $this->subscription->setStatus('active');
        $this->subscription->setEndedAt('2028-10-05 00:00:00');
        $this->subscriptions->method('findByOrganizationId')->willReturnCallback(fn () => $this->subscription);
        $this->plan = new Plan();
        $this->plan->setMaxMembers(100);
        $this->plans->method('find')->willReturnCallback(fn () => $this->plan);

        $this->mailer->method('validateMailAddress')->willReturnCallback(static fn (string $email): bool => str_contains($email, '@'));
        $this->mailer->method('createEMailTemplate')->willReturn($this->createMock(IEMailTemplate::class));
        $this->mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
        $this->mailer->method('send')->willReturn([]);

        $this->grants->method('insert')->willReturnCallback(static function (ExternalGrant $grant): ExternalGrant {
            $grant->setId(700);
            return $grant;
        });
        $this->grants->method('update')->willReturnArgument(0);
        $this->externals->method('insert')->willReturnArgument(0);
        $this->externals->method('update')->willReturnArgument(0);
        $this->userManager->method('getByEmail')->willReturn([]);

        $random = $this->createMock(ISecureRandom::class);
        $random->method('generate')->willReturnCallback(static fn (int $length): string => str_repeat('a', $length));
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getDateTime')->willReturnCallback(
            static fn (): \DateTime => new \DateTime(self::NOW, new \DateTimeZone('UTC')),
        );
        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event): void {
            $this->events[] = $event;
        });
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturnCallback(
            static fn (string $route, array $params): string => 'https://cloud.test/invite/' . $params['token'],
        );

        $this->service = new ExternalCollaboratorService(
            $this->externals,
            $this->grants,
            $this->invites,
            $this->members,
            $this->createMock(OrganizationMapper::class),
            $this->subscriptions,
            $this->plans,
            $this->userManager,
            $this->groupManager,
            $random,
            $this->mailer,
            $urls,
            $dispatcher,
            $this->createMock(IDBConnection::class),
            $time,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testInvitingANewEmailCreatesAnAccountAndAPendingGrant(): void
    {
        $user = $this->createMock(IUser::class);
        $user->expects($this->once())->method('setSystemEMailAddress')->with('jan@client.nl');
        $user->expects($this->once())->method('setDisplayName')->with('Jan Klant (Klant BV)');
        $this->userManager->method('createUser')->willReturn($user);
        $group = $this->createMock(IGroup::class);
        $group->expects($this->once())->method('addUser')->with($user);
        $this->groupManager->method('get')->with('externals')->willReturn($group);
        $this->invites->expects($this->once())->method('insert')->willReturnCallback(function (ExternalInvite $invite): ExternalInvite {
            $this->assertSame(hash('sha256', str_repeat('a', 43)), $invite->getTokenHash());
            $this->assertSame(700, $invite->getGrantId());
            return $invite;
        });

        $result = $this->invite(' Jan@Client.NL ', company: 'Klant BV');

        $this->assertSame(External::STATUS_INVITED, $result['external']->getStatus());
        $this->assertSame('jan@client.nl', $result['external']->getEmail());
        $this->assertSame(ExternalGrant::STATUS_PENDING, $result['grant']->getStatus());
        $this->assertSame(['client_developer'], $result['grant']->getFunctionalRoleKeyList());
        $this->assertSame(['informed'], $result['grant']->getDrasciRoleList());
        $this->assertFalse($result['activated']);
        $this->assertTrue($result['emailSent']);
        $this->assertNull($result['inviteUrl'], 'the link is only returned when the email failed');
        $this->assertSame([], $this->events, 'nothing is granted before the invitation is accepted');
    }

    public function testInviteReturnsTheLinkWhenTheEmailCannotBeSent(): void
    {
        $this->userManager->method('createUser')->willReturn($this->createMock(IUser::class));
        $this->rebuildMailerFailing();

        $result = $this->invite('jan@client.nl');

        $this->assertFalse($result['emailSent']);
        $this->assertSame('https://cloud.test/invite/' . str_repeat('a', 43), $result['inviteUrl']);
    }

    public function testKnownActiveExternalIsGrantedAtOnce(): void
    {
        $this->externals->method('findByEmail')->willReturn($this->external('ext_known', External::STATUS_ACTIVE));
        $this->grants->method('holdsSeat')->willReturn(true);
        $this->userManager->expects($this->never())->method('createUser');
        $this->invites->expects($this->never())->method('insert');

        $result = $this->invite('known@client.nl');

        $this->assertTrue($result['activated']);
        $this->assertSame(ExternalGrant::STATUS_ACTIVE, $result['grant']->getStatus());
        $this->assertCount(1, $this->events);
        $this->assertInstanceOf(ExternalGrantActivatedEvent::class, $this->events[0]);
        $this->assertSame(self::PROJECT, $this->events[0]->getProjectId());
        $this->assertSame('ext_known', $this->events[0]->getUserId());
        $this->assertSame(['informed'], $this->events[0]->getDrasciRoles());
    }

    public function testInvitingADisabledExternalWhoAcceptedBeforeReenablesAndGrantsAtOnce(): void
    {
        $external = $this->external('ext_back', External::STATUS_DISABLED);
        $external->setActivatedAt(new \DateTime('2026-01-01 00:00:00', new \DateTimeZone('UTC')));
        $external->setDisabledAt(new \DateTime('2026-09-01 00:00:00', new \DateTimeZone('UTC')));
        $this->externals->method('findByEmail')->willReturn($external);
        $user = $this->createMock(IUser::class);
        $user->expects($this->once())->method('setEnabled')->with(true);
        $this->userManager->method('get')->with('ext_back')->willReturn($user);

        $result = $this->invite('back@client.nl');

        $this->assertTrue($result['activated']);
        $this->assertSame(External::STATUS_ACTIVE, $external->getStatus());
        $this->assertNull($external->getDisabledAt());
    }

    public function testInvitingADisabledExternalWhoNeverAcceptedSendsANewLink(): void
    {
        $this->externals->method('findByEmail')->willReturn($this->external('ext_never', External::STATUS_DISABLED));
        $this->userManager->method('get')->willReturn($this->createMock(IUser::class));
        $this->invites->expects($this->once())->method('insert')->willReturnArgument(0);

        $result = $this->invite('never@client.nl');

        $this->assertFalse($result['activated']);
        $this->assertSame(ExternalGrant::STATUS_PENDING, $result['grant']->getStatus());
    }

    public function testInviteRefusesASuspendedExternal(): void
    {
        $this->externals->method('findByEmail')->willReturn($this->external('ext_blocked', External::STATUS_SUSPENDED));

        $this->expectException(OCSForbiddenException::class);
        $this->invite('blocked@client.nl');
    }

    public function testInviteRefusesOrganizationMembers(): void
    {
        $user = $this->createConfiguredMock(IUser::class, ['getUID' => 'sanne']);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->rebuildWithUserManager();
        $this->userManager->method('getByEmail')->willReturn([$user]);
        $this->members->method('getOrganizationMembership')->with('sanne')->willReturn(['organization_id' => self::ORG, 'role' => 'admin']);

        $this->expectException(OCSException::class);
        $this->expectExceptionMessage('already a member of the organization');
        $this->invite('sanne@demo.nl');
    }

    public function testInviteRefusesMembersOfOtherOrganizations(): void
    {
        $user = $this->createConfiguredMock(IUser::class, ['getUID' => 'piet']);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->rebuildWithUserManager();
        $this->userManager->method('getByEmail')->willReturn([$user]);
        $this->members->method('getOrganizationMembership')->willReturn(['organization_id' => 9, 'role' => 'member']);

        $this->expectException(OCSException::class);
        $this->expectExceptionMessage('another organization');
        $this->invite('piet@other.nl');
    }

    public function testInviteRefusesWhenAllSeatsAreTaken(): void
    {
        $this->plan->setMaxMembers(14);
        $this->members->method('countUsersInOrganization')->willReturn(13);
        $this->grants->method('countSeatHolders')->willReturn(1);
        $this->userManager->expects($this->never())->method('createUser');

        $this->expectException(OCSForbiddenException::class);
        $this->expectExceptionMessage('14 of 14 seats used');
        $this->invite('jan@client.nl');
    }

    public function testExternalAlreadyHoldingASeatNeedsNoNewSeat(): void
    {
        $this->plan->setMaxMembers(14);
        $this->members->method('countUsersInOrganization')->willReturn(13);
        $this->grants->method('countSeatHolders')->willReturn(1);
        $this->externals->method('findByEmail')->willReturn($this->external('ext_known', External::STATUS_ACTIVE));
        $this->grants->method('holdsSeat')->with(self::ORG, 'ext_known')->willReturn(true);

        $result = $this->invite('known@client.nl');

        $this->assertTrue($result['activated']);
    }

    public function testInviteRefusesAPersonAlreadyOnTheProject(): void
    {
        $this->externals->method('findByEmail')->willReturn($this->external('ext_known', External::STATUS_ACTIVE));
        $this->grants->method('findByProjectAndUser')->willReturn($this->grant('ext_known', ExternalGrant::STATUS_ACTIVE));

        $this->expectException(OCSException::class);
        $this->expectExceptionCode(409);
        $this->invite('known@client.nl');
    }

    public function testInviteRefusesAnExpiredSubscription(): void
    {
        $this->subscription->setEndedAt('2026-10-01 00:00:00');

        $this->expectException(OCSForbiddenException::class);
        $this->expectExceptionMessage('expired');
        $this->invite('jan@client.nl');
    }

    public function testAcceptActivatesEveryPendingGrant(): void
    {
        $token = 'secret-token';
        $this->invites->method('findByTokenHash')->with(hash('sha256', $token))->willReturn($this->openInvite('ext_new'));
        $this->externals->method('findByUserUid')->willReturn($this->external('ext_new', External::STATUS_INVITED));
        $user = $this->createMock(IUser::class);
        $user->expects($this->once())->method('setPassword')->with('Long-Password-2026!')->willReturn(true);
        $this->userManager->method('get')->with('ext_new')->willReturn($user);
        $expired = $this->grant('ext_new', ExternalGrant::STATUS_PENDING, 40);
        $expired->setExpiresAt(new \DateTime('2026-10-01'));
        $this->grants->method('findByUser')->willReturn([
            $this->grant('ext_new', ExternalGrant::STATUS_PENDING, 39),
            $this->grant('ext_new', ExternalGrant::STATUS_PENDING, 41),
            $expired,
        ]);
        $this->invites->expects($this->once())->method('invalidateOpenForUser');

        $external = $this->service->acceptInvite($token, 'Long-Password-2026!');

        $this->assertSame(External::STATUS_ACTIVE, $external->getStatus());
        $this->assertSame([39, 41], array_map(static fn (ExternalGrantActivatedEvent $event): int => $event->getProjectId(), $this->events));
    }

    public function testAcceptRefusesAUsedLink(): void
    {
        $invite = $this->openInvite('ext_new');
        $invite->setUsedAt(new \DateTime('2026-10-04'));
        $this->invites->method('findByTokenHash')->willReturn($invite);

        $this->expectException(OCSNotFoundException::class);
        $this->service->acceptInvite('secret-token', 'Long-Password-2026!');
    }

    public function testAcceptRefusesAnExpiredLink(): void
    {
        $invite = $this->openInvite('ext_new');
        $invite->setExpiresAt(new \DateTime('2026-10-05 11:59:59', new \DateTimeZone('UTC')));
        $this->invites->method('findByTokenHash')->willReturn($invite);

        $this->expectException(OCSNotFoundException::class);
        $this->service->acceptInvite('secret-token', 'Long-Password-2026!');
    }

    public function testRevokingAnActiveGrantRemovesProjectAccess(): void
    {
        $this->grants->method('findByProjectAndUser')->willReturn($this->grant('ext_known', ExternalGrant::STATUS_ACTIVE));
        $this->grants->method('findByUser')->willReturn([]);
        $this->invites->expects($this->once())->method('invalidateOpenForUser');

        $grant = $this->service->revoke(self::ORG, self::PROJECT, 'ext_known', 'sanne');

        $this->assertSame(ExternalGrant::STATUS_REVOKED, $grant->getStatus());
        $this->assertSame('sanne', $grant->getRevokedBy());
        $this->assertCount(1, $this->events);
        $this->assertInstanceOf(ExternalGrantRevokedEvent::class, $this->events[0]);
    }

    public function testRevokingAPendingGrantHasNothingToRemove(): void
    {
        $this->grants->method('findByProjectAndUser')->willReturn($this->grant('ext_new', ExternalGrant::STATUS_PENDING));
        $this->grants->method('findByUser')->willReturn([$this->grant('ext_new', ExternalGrant::STATUS_PENDING, 41)]);
        $this->invites->expects($this->never())->method('invalidateOpenForUser');

        $grant = $this->service->revoke(self::ORG, self::PROJECT, 'ext_new', 'sanne');

        $this->assertSame(ExternalGrant::STATUS_REVOKED, $grant->getStatus());
        $this->assertSame([], $this->events);
    }

    public function testRevokeRefusesAGrantOfAnotherOrganization(): void
    {
        $this->grants->method('findByProjectAndUser')->willReturn($this->grant('ext_known', ExternalGrant::STATUS_ACTIVE));

        $this->expectException(OCSNotFoundException::class);
        $this->service->revoke(9, self::PROJECT, 'ext_known', 'piet');
    }

    public function testResendReplacesTheLinkOfAPendingInvitation(): void
    {
        $this->grants->method('findByProjectAndUser')->willReturn($this->grant('ext_new', ExternalGrant::STATUS_PENDING));
        $this->externals->method('findByUserUid')->willReturn($this->external('ext_new', External::STATUS_INVITED));
        $this->invites->expects($this->once())->method('invalidateOpenForUser')->with('ext_new');
        $this->invites->expects($this->once())->method('insert')->willReturnCallback(function (ExternalInvite $invite): ExternalInvite {
            $this->assertSame(390, $invite->getGrantId());
            return $invite;
        });

        $result = $this->service->resend(self::ORG, self::PROJECT, 'ext_new', 'sanne');

        $this->assertTrue($result['emailSent']);
        $this->assertNull($result['inviteUrl']);
    }

    public function testResendRefusesAnAcceptedInvitation(): void
    {
        $this->grants->method('findByProjectAndUser')->willReturn($this->grant('ext_known', ExternalGrant::STATUS_ACTIVE));

        $this->expectException(OCSBadRequestException::class);
        $this->service->resend(self::ORG, self::PROJECT, 'ext_known', 'sanne');
    }

    public function testChangingTheEndDateAllowsANewWarning(): void
    {
        $grant = $this->grant('ext_known', ExternalGrant::STATUS_ACTIVE);
        $grant->setWarnedAt(new \DateTime('2026-10-01'));
        $this->grants->method('findByProjectAndUser')->willReturn($grant);

        $updated = $this->service->changeEndDate(self::ORG, self::PROJECT, 'ext_known', new \DateTime('2027-03-31 23:59:59'));

        $this->assertSame('2027-03-31', $updated->getExpiresAt()->format('Y-m-d'));
        $this->assertNull($updated->getWarnedAt());
    }

    public function testChangingTheEndDateRefusesThePastAndEndedGrants(): void
    {
        $this->grants->method('findByProjectAndUser')->willReturnOnConsecutiveCalls(
            $this->grant('ext_known', ExternalGrant::STATUS_ACTIVE),
            $this->grant('ext_known', ExternalGrant::STATUS_REVOKED),
        );

        try {
            $this->service->changeEndDate(self::ORG, self::PROJECT, 'ext_known', new \DateTime('2026-10-01'));
            $this->fail('a past end date was accepted');
        } catch (OCSBadRequestException) {
        }

        $this->expectException(OCSNotFoundException::class);
        $this->service->changeEndDate(self::ORG, self::PROJECT, 'ext_known', new \DateTime('2027-03-31'));
    }

    public function testUsableGrantsSkipExpiredGrantsAndLapsedSubscriptions(): void
    {
        $expired = $this->grant('ext_known', ExternalGrant::STATUS_ACTIVE, 40);
        $expired->setExpiresAt(new \DateTime('2026-10-01'));
        $current = $this->grant('ext_known', ExternalGrant::STATUS_ACTIVE, 39);
        $current->setExpiresAt(new \DateTime('2026-12-31'));
        $this->grants->method('findByUser')->willReturn([$expired, $current]);

        $this->assertSame([39], array_map(static fn (ExternalGrant $grant): int => $grant->getProjectId(), $this->service->getUsableGrants('ext_known')));

        $this->subscription->setStatus('paused');
        $this->assertSame([], $this->service->getUsableGrants('ext_known'));
    }

    public function testSeatsCountMembersAndExternals(): void
    {
        $this->members->method('countUsersInOrganization')->with(self::ORG)->willReturn(14);
        $this->grants->method('countSeatHolders')->with(self::ORG)->willReturn(2);

        $this->assertSame(16, $this->service->countUsedSeats(self::ORG));
    }

    /** @return array{external: External, grant: ExternalGrant, activated: bool, emailSent: bool, inviteUrl: ?string} */
    private function invite(string $email, ?string $company = null): array
    {
        return $this->service->invite(
            self::ORG,
            self::PROJECT,
            'sanne',
            $email,
            'Jan Klant',
            $company,
            null,
            new \DateTime('2027-01-31 23:59:59', new \DateTimeZone('UTC')),
            ['client_developer'],
            ['informed'],
        );
    }

    private function external(string $uid, string $status): External
    {
        $external = new External();
        $external->setUserUid($uid);
        $external->setEmail($uid . '@client.nl');
        $external->setDisplayName('Jan Klant');
        $external->setStatus($status);
        return $external;
    }

    private function grant(string $uid, string $status, int $projectId = self::PROJECT): ExternalGrant
    {
        $grant = new ExternalGrant();
        $grant->setId($projectId * 10);
        $grant->setOrganizationId(self::ORG);
        $grant->setProjectId($projectId);
        $grant->setUserUid($uid);
        $grant->setStatus($status);
        $grant->setDrasciRoleList(['informed']);
        $grant->setFunctionalRoleKeyList([]);
        return $grant;
    }

    private function openInvite(string $uid): ExternalInvite
    {
        $invite = new ExternalInvite();
        $invite->setUserUid($uid);
        $invite->setGrantId(390);
        $invite->setExpiresAt(new \DateTime('2026-10-12 12:00:00', new \DateTimeZone('UTC')));
        return $invite;
    }

    private function rebuildWithUserManager(): void
    {
        $this->rebuild(userManager: $this->userManager);
    }

    private function rebuildMailerFailing(): void
    {
        $mailer = $this->createMock(IMailer::class);
        $mailer->method('validateMailAddress')->willReturn(true);
        $mailer->method('createEMailTemplate')->willReturn($this->createMock(IEMailTemplate::class));
        $mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
        $mailer->method('send')->willThrowException(new \RuntimeException('smtp down'));
        $this->rebuild(mailer: $mailer);
    }

    private function rebuild(?IUserManager $userManager = null, ?IMailer $mailer = null): void
    {
        $reflection = new \ReflectionClass($this->service);
        foreach (['userManager' => $userManager, 'mailer' => $mailer] as $property => $value) {
            if ($value !== null) {
                $reflection->getProperty($property)->setValue($this->service, $value);
            }
        }
    }
}
