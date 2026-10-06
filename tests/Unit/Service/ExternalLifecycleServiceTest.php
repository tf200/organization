<?php

declare(strict_types=1);

namespace OCA\Organization\Tests\Unit\Service;

use OCA\Organization\Db\External;
use OCA\Organization\Db\ExternalAuditEntry;
use OCA\Organization\Db\ExternalAuditMapper;
use OCA\Organization\Db\ExternalGrant;
use OCA\Organization\Db\ExternalGrantMapper;
use OCA\Organization\Db\ExternalInviteMapper;
use OCA\Organization\Db\ExternalMapper;
use OCA\Organization\Event\ExternalGrantRevokedEvent;
use OCA\Organization\Event\ExternalPrivateFolderReleaseEvent;
use OCA\Organization\Notification\NotificationConstants;
use OCA\Organization\Service\ExternalAuditService;
use OCA\Organization\Service\ExternalCollaboratorService;
use OCA\Organization\Service\ExternalLifecycleService;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Runs the lifecycle against in-memory grants and externals. The mapper
 * fakes select the way the SQL does, so each test only sets up the rows.
 */
class ExternalLifecycleServiceTest extends TestCase
{
    private const NOW = '2026-10-06 12:00:00';

    /** @var ExternalGrant[] */
    private array $grants = [];

    /** @var array<string,External> */
    private array $externals = [];

    /** @var object[] */
    private array $events = [];

    /** @var string[] subjects of sent emails */
    private array $mails = [];

    /** @var string[] subjects of bell notifications */
    private array $notifications = [];

    /** @var string[] */
    private array $disabledUsers = [];

    /** @var ExternalAuditEntry[] */
    private array $audit = [];

    /** What describeDeadLink answers. */
    private array $deadLink = ['state' => 'unknown', 'external' => null, 'grants' => []];

    /** @var string[] */
    private array $deletedUsers = [];

    /** Whether the project app hands over folders it is asked about. */
    private bool $releaseFolders = true;

    private ExternalLifecycleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $grantMapper = $this->createMock(ExternalGrantMapper::class);
        $grantMapper->method('update')->willReturnArgument(0);
        $grantMapper->method('findByUser')->willReturnCallback(
            fn (string $uid, array $statuses): array => $this->grantsWhere(
                static fn (ExternalGrant $g): bool => $g->getUserUid() === $uid && in_array($g->getStatus(), $statuses, true),
            ),
        );
        $grantMapper->method('findToWarn')->willReturnCallback(
            fn (\DateTime $now, \DateTime $until): array => $this->grantsWhere(
                static fn (ExternalGrant $g): bool => $g->getStatus() === ExternalGrant::STATUS_ACTIVE && $g->getWarnedAt() === null
                    && $g->getExpiresAt() !== null && $g->getExpiresAt() > $now && $g->getExpiresAt() <= $until,
            ),
        );
        $grantMapper->method('findDue')->willReturnCallback(
            fn (\DateTime $now): array => $this->grantsWhere(
                static fn (ExternalGrant $g): bool => in_array($g->getStatus(), [ExternalGrant::STATUS_PENDING, ExternalGrant::STATUS_ACTIVE], true)
                    && $g->getExpiresAt() !== null && $g->getExpiresAt() <= $now,
            ),
        );
        $grantMapper->method('findStalePending')->willReturnCallback(
            fn (\DateTime $before): array => $this->grantsWhere(
                static fn (ExternalGrant $g): bool => $g->getStatus() === ExternalGrant::STATUS_PENDING && $g->getInvitedAt() <= $before,
            ),
        );
        $grantMapper->method('findFoldersToRelease')->willReturnCallback(
            fn (\DateTime $before): array => $this->grantsWhere(
                static fn (ExternalGrant $g): bool => in_array($g->getStatus(), [ExternalGrant::STATUS_EXPIRED, ExternalGrant::STATUS_REVOKED], true)
                    && $g->getAcceptedAt() !== null && $g->getFolderReleasedAt() === null
                    && $g->getRevokedAt() !== null && $g->getRevokedAt() <= $before,
            ),
        );

        $externalMapper = $this->createMock(ExternalMapper::class);
        $externalMapper->method('findByUserUid')->willReturnCallback(fn (string $uid): ?External => $this->externals[$uid] ?? null);
        $externalMapper->method('findByStatuses')->willReturnCallback(
            fn (array $statuses): array => array_values(array_filter(
                $this->externals,
                static fn (External $e): bool => in_array($e->getStatus(), $statuses, true),
            )),
        );
        $externalMapper->method('update')->willReturnArgument(0);
        $externalMapper->method('delete')->willReturnCallback(function (External $external): External {
            unset($this->externals[$external->getUserUid()]);
            return $external;
        });

        $collaborators = $this->createMock(ExternalCollaboratorService::class);
        $collaborators->method('projectName')->willReturn('Zuidas');
        $collaborators->method('describeDeadLink')->willReturnCallback(fn (): array => $this->deadLink);

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturnCallback(function (string $uid): IUser {
            $user = $this->createMock(IUser::class);
            $user->method('setEnabled')->willReturnCallback(function (bool $enabled) use ($uid): void {
                if (!$enabled) {
                    $this->disabledUsers[] = $uid;
                }
            });
            $user->method('delete')->willReturnCallback(function () use ($uid): bool {
                $this->deletedUsers[] = $uid;
                return true;
            });
            return $user;
        });

        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event): void {
            if ($event instanceof ExternalPrivateFolderReleaseEvent && $this->releaseFolders) {
                $event->markReleased();
            }
            $this->events[] = $event;
        });

        $mailer = $this->createMock(IMailer::class);
        $mailer->method('createEMailTemplate')->willReturnCallback(function (): IEMailTemplate {
            $template = $this->createMock(IEMailTemplate::class);
            $template->method('setSubject')->willReturnCallback(function (string $subject): void {
                $this->mails[] = $subject;
            });
            return $template;
        });
        $mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
        $mailer->method('send')->willReturn([]);

        $notificationManager = $this->createMock(INotificationManager::class);
        $notificationManager->method('createNotification')->willReturnCallback(function (): INotification {
            $notification = $this->createMock(INotification::class);
            foreach (['setApp', 'setUser', 'setDateTime', 'setObject'] as $setter) {
                $notification->method($setter)->willReturnSelf();
            }
            $notification->method('setSubject')->willReturnCallback(function (string $subject) use ($notification): INotification {
                $this->notifications[] = $subject;
                return $notification;
            });
            return $notification;
        });

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime(self::NOW, new \DateTimeZone('UTC')));

        $this->service = new ExternalLifecycleService(
            $grantMapper,
            $externalMapper,
            $this->createMock(ExternalInviteMapper::class),
            $collaborators,
            $userManager,
            $dispatcher,
            $mailer,
            $notificationManager,
            $this->createMock(IURLGenerator::class),
            $time,
            $this->createMock(LoggerInterface::class),
            $this->auditService($time),
        );
    }

    private function auditService(ITimeFactory $time): ExternalAuditService
    {
        $mapper = $this->createMock(ExternalAuditMapper::class);
        $mapper->method('insert')->willReturnCallback(function (ExternalAuditEntry $entry): ExternalAuditEntry {
            $this->audit[] = $entry;
            return $entry;
        });
        $mapper->method('findLatest')->willReturnCallback(function (string $uid, string $action): ?ExternalAuditEntry {
            foreach (array_reverse($this->audit) as $entry) {
                if ($entry->getUserUid() === $uid && $entry->getAction() === $action) {
                    return $entry;
                }
            }
            return null;
        });
        return new ExternalAuditService($mapper, $this->createMock(IEventDispatcher::class), $time, $this->createMock(LoggerInterface::class));
    }

    /** @return string[] */
    private function auditActions(): array
    {
        return array_map(static fn (ExternalAuditEntry $entry): string => $entry->getAction() . ' ' . $entry->getUserUid() . ' ' . ($entry->getOrganizationId() ?? '-'), $this->audit);
    }

    /**
     * @return ExternalGrant[]
     */
    private function grantsWhere(callable $filter): array
    {
        return array_values(array_filter($this->grants, $filter));
    }

    private static function at(string $modifier): \DateTime
    {
        return (new \DateTime(self::NOW, new \DateTimeZone('UTC')))->modify($modifier);
    }

    private function grant(string $uid, string $status, array $dates = []): ExternalGrant
    {
        $grant = new ExternalGrant();
        $grant->setId(count($this->grants) + 1);
        $grant->setOrganizationId(5);
        $grant->setProjectId(39);
        $grant->setUserUid($uid);
        $grant->setStatus($status);
        $grant->setInvitedBy('sanne');
        $grant->setInvitedAt($dates['invitedAt'] ?? self::at('-60 days'));
        $grant->setAcceptedAt($dates['acceptedAt'] ?? ($status === ExternalGrant::STATUS_PENDING ? null : self::at('-59 days')));
        $grant->setExpiresAt($dates['expiresAt'] ?? null);
        $grant->setRevokedAt($dates['endedAt'] ?? null);
        $grant->setFolderReleasedAt($dates['folderReleasedAt'] ?? null);
        return $this->grants[] = $grant;
    }

    private function external(string $uid, string $status, ?\DateTime $disabledAt = null): External
    {
        $external = new External();
        $external->setUserUid($uid);
        $external->setEmail($uid . '@example.com');
        $external->setDisplayName(ucfirst($uid));
        $external->setStatus($status);
        $external->setCreatedAt(self::at('-200 days'));
        $external->setDisabledAt($disabledAt);
        return $this->externals[$uid] = $external;
    }

    public function testWarnsOnceAWeekBeforeTheEnd(): void
    {
        $this->external('klaas', External::STATUS_ACTIVE);
        $soon = $this->grant('klaas', ExternalGrant::STATUS_ACTIVE, ['expiresAt' => self::at('+3 days')]);
        $later = $this->grant('klaas', ExternalGrant::STATUS_ACTIVE, ['expiresAt' => self::at('+10 days')]);

        $this->assertSame(1, $this->service->warnExpiring());
        $this->assertSame(0, $this->service->warnExpiring(), 'A warned grant is not warned again');

        $this->assertNotNull($soon->getWarnedAt());
        $this->assertNull($later->getWarnedAt());
        $this->assertSame(['Your access to Zuidas ends on 2026-10-09'], $this->mails);
        $this->assertSame([NotificationConstants::SUBJECT_EXTERNAL_ACCESS_EXPIRING], $this->notifications);
    }

    public function testExpiresDueGrantsAndTakesActiveOnesOffTheProject(): void
    {
        $this->external('klaas', External::STATUS_ACTIVE);
        $active = $this->grant('klaas', ExternalGrant::STATUS_ACTIVE, ['expiresAt' => self::at('-1 hour')]);
        $pending = $this->grant('klaas', ExternalGrant::STATUS_PENDING, ['expiresAt' => self::at('-1 hour')]);
        $running = $this->grant('klaas', ExternalGrant::STATUS_ACTIVE, ['expiresAt' => self::at('+1 day')]);

        $this->assertSame(1, $this->service->expireDue());

        $this->assertSame(ExternalGrant::STATUS_EXPIRED, $active->getStatus());
        $this->assertSame(ExternalGrant::STATUS_EXPIRED, $pending->getStatus());
        $this->assertSame(ExternalGrant::STATUS_ACTIVE, $running->getStatus());
        $this->assertEquals(self::at('+0 days'), $active->getRevokedAt());

        $this->assertCount(1, $this->events);
        $this->assertInstanceOf(ExternalGrantRevokedEvent::class, $this->events[0]);
        $this->assertSame(ExternalGrantRevokedEvent::REASON_EXPIRED, $this->events[0]->getReason());
        $this->assertSame(['Your access to Zuidas has ended'], $this->mails);
        $this->assertSame([NotificationConstants::SUBJECT_EXTERNAL_ACCESS_ENDED], $this->notifications);
        $this->assertSame(['expired klaas 5', 'expired klaas 5'], $this->auditActions());
        $this->assertSame('end_date', $this->audit[0]->getDetailMap()['reason']);
        $this->assertNull($this->audit[0]->getActorUid());
    }

    public function testStaleInvitesEndAndUnusedAccountsGo(): void
    {
        $this->external('stale', External::STATUS_INVITED);
        $stale = $this->grant('stale', ExternalGrant::STATUS_PENDING, ['invitedAt' => self::at('-31 days')]);
        $this->external('cancelled', External::STATUS_INVITED);
        $this->grant('cancelled', ExternalGrant::STATUS_REVOKED, ['endedAt' => self::at('-1 day')]);
        $this->external('fresh', External::STATUS_INVITED);
        $this->grant('fresh', ExternalGrant::STATUS_PENDING, ['invitedAt' => self::at('-3 days')]);

        $this->assertSame(1, $this->service->dropStaleInvites());

        $this->assertSame(ExternalGrant::STATUS_EXPIRED, $stale->getStatus());
        $this->assertEqualsCanonicalizing(['stale', 'cancelled'], $this->deletedUsers);
        $this->assertSame(['fresh'], array_keys($this->externals));
        $this->assertSame(['expired stale 5', 'account_deleted stale 5', 'account_deleted cancelled 5'], $this->auditActions());
        $this->assertSame('not_accepted', $this->audit[0]->getDetailMap()['reason']);
    }

    public function testReleasesFoldersThirtyDaysAfterAccessEnded(): void
    {
        $due = $this->grant('klaas', ExternalGrant::STATUS_EXPIRED, ['endedAt' => self::at('-31 days')]);
        $recent = $this->grant('klaas', ExternalGrant::STATUS_REVOKED, ['endedAt' => self::at('-5 days')]);
        $neverUsed = $this->grant('other', ExternalGrant::STATUS_EXPIRED, ['endedAt' => self::at('-40 days'), 'acceptedAt' => null]);
        $neverUsed->setAcceptedAt(null);

        $this->assertSame(1, $this->service->releaseFolders());

        $this->assertNotNull($due->getFolderReleasedAt());
        $this->assertNull($recent->getFolderReleasedAt());
        $this->assertInstanceOf(ExternalPrivateFolderReleaseEvent::class, $this->events[0]);
    }

    public function testFolderTheProjectAppCouldNotMoveIsRetried(): void
    {
        $this->releaseFolders = false;
        $grant = $this->grant('klaas', ExternalGrant::STATUS_EXPIRED, ['endedAt' => self::at('-31 days')]);

        $this->assertSame(0, $this->service->releaseFolders());
        $this->assertNull($grant->getFolderReleasedAt());
    }

    public function testDisablesAccountsThirtyDaysWithoutAccess(): void
    {
        $idle = $this->external('idle', External::STATUS_ACTIVE);
        $this->grant('idle', ExternalGrant::STATUS_EXPIRED, ['endedAt' => self::at('-31 days')]);
        $recent = $this->external('recent', External::STATUS_ACTIVE);
        $this->grant('recent', ExternalGrant::STATUS_REVOKED, ['endedAt' => self::at('-10 days')]);
        $busy = $this->external('busy', External::STATUS_ACTIVE);
        $this->grant('busy', ExternalGrant::STATUS_EXPIRED, ['endedAt' => self::at('-60 days')]);
        $this->grant('busy', ExternalGrant::STATUS_ACTIVE);

        $this->assertSame(1, $this->service->disableIdleAccounts());

        $this->assertSame(External::STATUS_DISABLED, $idle->getStatus());
        $this->assertNotNull($idle->getDisabledAt());
        $this->assertSame(['idle'], $this->disabledUsers);
        $this->assertSame(['account_disabled idle 5'], $this->auditActions());
        $this->assertSame(External::STATUS_ACTIVE, $recent->getStatus());
        $this->assertSame(External::STATUS_ACTIVE, $busy->getStatus());
    }

    public function testDeletesDisabledAccountsOnlyAfterNinetyDaysAndFolderHandover(): void
    {
        $this->external('gone', External::STATUS_DISABLED, self::at('-60 days'));
        $this->grant('gone', ExternalGrant::STATUS_EXPIRED, ['endedAt' => self::at('-91 days'), 'folderReleasedAt' => self::at('-61 days')]);
        $this->external('folder', External::STATUS_DISABLED, self::at('-60 days'));
        $this->grant('folder', ExternalGrant::STATUS_EXPIRED, ['endedAt' => self::at('-91 days')]);
        $this->external('young', External::STATUS_DISABLED, self::at('-30 days'));
        $this->grant('young', ExternalGrant::STATUS_EXPIRED, ['endedAt' => self::at('-61 days'), 'folderReleasedAt' => self::at('-31 days')]);

        $this->assertSame(1, $this->service->deleteDisabledAccounts());

        $this->assertSame(['gone'], $this->deletedUsers);
        $this->assertEqualsCanonicalizing(['folder', 'young'], array_keys($this->externals));
    }

    public function testANewLinkRequestTellsTheInvitersOnceADay(): void
    {
        $external = $this->external('waiting', External::STATUS_INVITED);
        $this->deadLink = [
            'state' => 'requestable',
            'external' => $external,
            'grants' => [
                $this->grant('waiting', ExternalGrant::STATUS_PENDING),
                $this->grant('waiting', ExternalGrant::STATUS_PENDING),
            ],
        ];

        $this->assertSame('requested', $this->service->requestNewLink('old-token'));
        $this->assertSame('already_requested', $this->service->requestNewLink('old-token'));

        $this->assertSame([NotificationConstants::SUBJECT_EXTERNAL_LINK_REQUESTED, NotificationConstants::SUBJECT_EXTERNAL_LINK_REQUESTED], $this->notifications);
        $this->assertSame(['link_requested waiting 5', 'link_requested waiting 5'], $this->auditActions());
        $this->assertSame([], $this->mails, 'the link is never sent to whoever asked');
    }

    public function testANewLinkIsNotRequestedForAcceptedOrUnknownLinks(): void
    {
        $this->assertSame('unknown', $this->service->requestNewLink('guessed'));
        $this->deadLink = ['state' => 'accepted', 'external' => $this->external('klaas', External::STATUS_ACTIVE), 'grants' => []];
        $this->assertSame('accepted', $this->service->requestNewLink('used'));

        $this->assertSame([], $this->notifications);
        $this->assertSame([], $this->audit);
    }
}
