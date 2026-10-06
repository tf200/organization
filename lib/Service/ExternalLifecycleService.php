<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCA\Organization\Db\External;
use OCA\Organization\Db\ExternalGrant;
use OCA\Organization\Db\ExternalGrantMapper;
use OCA\Organization\Db\ExternalInviteMapper;
use OCA\Organization\Db\ExternalMapper;
use OCA\Organization\Event\ExternalGrantRevokedEvent;
use OCA\Organization\Event\ExternalPrivateFolderReleaseEvent;
use OCA\Organization\Notification\NotificationConstants;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\Headers\AutoSubmitted;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What happens to external collaborators over time: a warning before a grant
 * ends, the end itself, and the clean-up of accounts nobody uses any more.
 *
 * A grant ends at its end date; 30 days later its private folder goes to the
 * project owner. An account without access for 30 days is disabled and, 90
 * days after its access ended, deleted once every folder has been handed over.
 */
class ExternalLifecycleService
{
    public const WARN_DAYS = 7;
    public const STALE_INVITE_DAYS = 30;
    public const RELEASE_FOLDER_DAYS = 30;
    public const DISABLE_AFTER_DAYS = 30;
    public const DELETE_AFTER_DAYS = 90;

    public function __construct(
        private ExternalGrantMapper $grantMapper,
        private ExternalMapper $externalMapper,
        private ExternalInviteMapper $inviteMapper,
        private ExternalCollaboratorService $externals,
        private IUserManager $userManager,
        private IEventDispatcher $eventDispatcher,
        private IMailer $mailer,
        private INotificationManager $notificationManager,
        private IURLGenerator $urlGenerator,
        private ITimeFactory $timeFactory,
        private LoggerInterface $logger,
        private ExternalAuditService $audit,
    ) {
    }

    /**
     * Someone opened an invitation link that no longer works and asks for a
     * new one. The inviters are told so they can resend it; the link itself is
     * never sent to whoever asked. One request a day per person.
     *
     * @return string 'requested', 'already_requested', 'accepted' or 'unknown'
     */
    public function requestNewLink(string $token): string
    {
        $link = $this->externals->describeDeadLink($token);
        $external = $link['external'];
        if ($link['state'] !== 'requestable' || $external === null) {
            return $link['state'];
        }

        $last = $this->audit->lastOf($external->getUserUid(), ExternalAuditService::LINK_REQUESTED);
        if ($last !== null && $last->getCreatedAt() > $this->daysFrom($this->now(), -1)) {
            return 'already_requested';
        }

        foreach ($link['grants'] as $grant) {
            $this->audit->record(ExternalAuditService::LINK_REQUESTED, $external->getUserUid(), $grant->getOrganizationId(), $grant->getProjectId(), null);
            $this->notifyInviter($grant, $external, NotificationConstants::SUBJECT_EXTERNAL_LINK_REQUESTED, $this->externals->projectName($grant->getProjectId()));
        }

        return 'requested';
    }

    /**
     * Tells the external and the inviter, once, that access ends within a week.
     */
    public function warnExpiring(): int
    {
        $now = $this->now();
        $count = 0;
        foreach ($this->grantMapper->findToWarn($now, $this->daysFrom($now, self::WARN_DAYS)) as $grant) {
            $grant->setWarnedAt($now);
            $this->grantMapper->update($grant);

            $external = $this->externalMapper->findByUserUid($grant->getUserUid());
            if ($external === null) {
                continue;
            }

            $projectName = $this->externals->projectName($grant->getProjectId());
            $endDate = $grant->getExpiresAt()?->format('Y-m-d') ?? '';
            $this->mailExternal(
                $external,
                sprintf('Your access to %s ends on %s', $projectName, $endDate),
                [sprintf('Your access to the project %s ends on %s. Ask the person who invited you if you need it for longer.', $projectName, $endDate)],
                $grant->getProjectId(),
            );
            $this->notifyInviter($grant, $external, NotificationConstants::SUBJECT_EXTERNAL_ACCESS_EXPIRING, $projectName, ['expiresAt' => $endDate]);
            $count++;
        }

        return $count;
    }

    /**
     * Ends grants past their end date and takes the externals off the projects.
     */
    public function expireDue(): int
    {
        $now = $this->now();
        $count = 0;
        foreach ($this->grantMapper->findDue($now) as $grant) {
            $wasActive = $grant->getStatus() === ExternalGrant::STATUS_ACTIVE;
            $this->end($grant, $now, 'end_date');
            if (!$wasActive) {
                continue;
            }

            $this->eventDispatcher->dispatchTyped(new ExternalGrantRevokedEvent(
                $grant->getOrganizationId(),
                $grant->getProjectId(),
                $grant->getUserUid(),
                ExternalGrantRevokedEvent::REASON_EXPIRED,
            ));

            $external = $this->externalMapper->findByUserUid($grant->getUserUid());
            if ($external !== null) {
                $projectName = $this->externals->projectName($grant->getProjectId());
                $this->mailExternal(
                    $external,
                    sprintf('Your access to %s has ended', $projectName),
                    [sprintf('Your access to the project %s has ended.', $projectName)],
                    null,
                );
                $this->notifyInviter($grant, $external, NotificationConstants::SUBJECT_EXTERNAL_ACCESS_ENDED, $projectName);
            }
            $count++;
        }

        return $count;
    }

    /**
     * Ends invitations nobody accepted within 30 days, then deletes accounts
     * that were never used and have no open invitation left, such as one
     * whose only invitation was cancelled.
     */
    public function dropStaleInvites(): int
    {
        $now = $this->now();
        $count = 0;
        foreach ($this->grantMapper->findStalePending($this->daysFrom($now, -self::STALE_INVITE_DAYS)) as $grant) {
            $this->end($grant, $now, 'not_accepted');
            $count++;
        }

        foreach ($this->externalMapper->findByStatuses([External::STATUS_INVITED]) as $external) {
            if (!$this->hasOpenGrant($external->getUserUid())) {
                $this->deleteAccount($external);
            }
        }

        return $count;
    }

    /**
     * Hands private project folders to the project owner 30 days after access
     * ended. A folder the project app could not move is retried the next day.
     */
    public function releaseFolders(): int
    {
        $count = 0;
        foreach ($this->grantMapper->findFoldersToRelease($this->daysFrom($this->now(), -self::RELEASE_FOLDER_DAYS)) as $grant) {
            $event = new ExternalPrivateFolderReleaseEvent($grant->getOrganizationId(), $grant->getProjectId(), $grant->getUserUid());
            try {
                $this->eventDispatcher->dispatchTyped($event);
            } catch (Throwable $e) {
                $this->logger->error('Failed to release the private folder of an external collaborator', [
                    'projectId' => $grant->getProjectId(),
                    'userId' => $grant->getUserUid(),
                    'exception' => $e,
                ]);
                continue;
            }

            if ($event->isReleased()) {
                $grant->setFolderReleasedAt($this->now());
                $this->grantMapper->update($grant);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Disables accounts that have had no open grant for 30 days.
     */
    public function disableIdleAccounts(): int
    {
        $now = $this->now();
        $cutoff = $this->daysFrom($now, -self::DISABLE_AFTER_DAYS);
        $count = 0;
        foreach ($this->externalMapper->findByStatuses([External::STATUS_ACTIVE]) as $external) {
            if ($this->hasOpenGrant($external->getUserUid())) {
                continue;
            }
            $lastEnd = $this->lastAccessEnd($external);
            if ($lastEnd === null || $lastEnd > $cutoff) {
                continue;
            }

            $this->userManager->get($external->getUserUid())?->setEnabled(false);
            $external->setStatus(External::STATUS_DISABLED);
            $external->setDisabledAt($now);
            $this->externalMapper->update($external);
            $this->recordForAccount(ExternalAuditService::ACCOUNT_DISABLED, $external->getUserUid());
            $count++;
        }

        return $count;
    }

    /**
     * Deletes disabled accounts 90 days after their access ended, once every
     * private folder has been handed over.
     */
    public function deleteDisabledAccounts(): int
    {
        $cutoff = $this->daysFrom($this->now(), -self::DELETE_AFTER_DAYS);
        $count = 0;
        foreach ($this->externalMapper->findByStatuses([External::STATUS_DISABLED]) as $external) {
            $lastEnd = $this->lastAccessEnd($external);
            if ($lastEnd === null || $lastEnd > $cutoff || $this->hasOpenGrant($external->getUserUid())) {
                continue;
            }
            if ($this->hasUnreleasedFolder($external->getUserUid())) {
                continue;
            }

            $this->deleteAccount($external);
            $count++;
        }

        return $count;
    }

    /**
     * @param string $reason 'end_date', or 'not_accepted' for an invitation nobody took up
     */
    private function end(ExternalGrant $grant, \DateTime $now, string $reason): void
    {
        $grant->setStatus(ExternalGrant::STATUS_EXPIRED);
        $grant->setRevokedAt($now);
        $this->grantMapper->update($grant);
        $this->audit->record(ExternalAuditService::EXPIRED, $grant->getUserUid(), $grant->getOrganizationId(), $grant->getProjectId(), null, [
            'reason' => $reason,
        ]);

        if (!$this->hasOpenGrant($grant->getUserUid())) {
            $this->inviteMapper->invalidateOpenForUser($grant->getUserUid(), $now);
        }
    }

    private function hasOpenGrant(string $userUid): bool
    {
        return $this->grantMapper->findByUser($userUid, [ExternalGrant::STATUS_PENDING, ExternalGrant::STATUS_ACTIVE]) !== [];
    }

    private function hasUnreleasedFolder(string $userUid): bool
    {
        foreach ($this->grantMapper->findByUser($userUid, [ExternalGrant::STATUS_EXPIRED, ExternalGrant::STATUS_REVOKED]) as $grant) {
            if ($grant->getAcceptedAt() !== null && $grant->getFolderReleasedAt() === null) {
                return true;
            }
        }
        return false;
    }

    /**
     * When the external last lost access: the latest end of their grants, or
     * when the account was created if no grant ever ended.
     */
    private function lastAccessEnd(External $external): ?\DateTime
    {
        $last = null;
        foreach ($this->grantMapper->findByUser($external->getUserUid(), [ExternalGrant::STATUS_EXPIRED, ExternalGrant::STATUS_REVOKED]) as $grant) {
            $end = $grant->getRevokedAt();
            if ($end !== null && ($last === null || $end > $last)) {
                $last = $end;
            }
        }
        return $last ?? $external->getCreatedAt();
    }

    private function deleteAccount(External $external): void
    {
        // Read before the account goes, in case its grants go with it.
        $organizationIds = $this->organizationsOf($external->getUserUid());
        try {
            $this->userManager->get($external->getUserUid())?->delete();
            $this->inviteMapper->deleteForUser($external->getUserUid());
            $this->externalMapper->delete($external);
            $this->recordForAccount(ExternalAuditService::ACCOUNT_DELETED, $external->getUserUid(), $organizationIds);
        } catch (Throwable $e) {
            $this->logger->error('Failed to delete an external collaborator account', [
                'userId' => $external->getUserUid(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Account events are written once for every organization the external
     * worked for, so each one's admins see them.
     *
     * @param ?int[] $organizationIds
     */
    private function recordForAccount(string $action, string $userUid, ?array $organizationIds = null): void
    {
        $organizationIds ??= $this->organizationsOf($userUid);
        if ($organizationIds === []) {
            $this->audit->record($action, $userUid, null, null, null);
            return;
        }
        foreach ($organizationIds as $organizationId) {
            $this->audit->record($action, $userUid, $organizationId, null, null);
        }
    }

    /** @return int[] */
    private function organizationsOf(string $userUid): array
    {
        $organizationIds = [];
        foreach ($this->grantMapper->findByUser($userUid, ExternalGrant::STATUSES) as $grant) {
            $organizationIds[$grant->getOrganizationId()] = true;
        }
        return array_keys($organizationIds);
    }

    /**
     * @param string[] $paragraphs
     */
    private function mailExternal(External $external, string $subject, array $paragraphs, ?int $projectId): void
    {
        try {
            $template = $this->mailer->createEMailTemplate('organization.ExternalAccess', ['userId' => $external->getUserUid()]);
            $template->setSubject($subject);
            $template->addHeader();
            $template->addHeading($subject);
            $template->addBodyText(sprintf('Hello %s,', $external->getDisplayName()));
            foreach ($paragraphs as $paragraph) {
                $template->addBodyText($paragraph);
            }
            if ($projectId !== null) {
                $template->addBodyButton('Open the project', $this->projectUrl($projectId));
            }
            $template->addFooter();

            $message = $this->mailer->createMessage();
            $message->setTo([$external->getEmail() => $external->getDisplayName()]);
            $message->useTemplate($template);
            $message->setAutoSubmitted(AutoSubmitted::VALUE_AUTO_GENERATED);
            $this->mailer->send($message);
        } catch (Throwable $e) {
            $this->logger->warning('Could not email an external collaborator', [
                'userId' => $external->getUserUid(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * @param array<string,string> $parameters
     */
    private function notifyInviter(ExternalGrant $grant, External $external, string $subject, string $projectName, array $parameters = []): void
    {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(NotificationConstants::APP_ID)
                ->setUser($grant->getInvitedBy())
                ->setDateTime($this->now())
                ->setObject(NotificationConstants::OBJECT_TYPE_EXTERNAL_GRANT, (string) $grant->getId())
                ->setSubject($subject, $parameters + [
                    'externalName' => $external->getDisplayName(),
                    'projectName' => $projectName,
                    'projectId' => $grant->getProjectId(),
                ]);
            $this->notificationManager->notify($notification);
        } catch (Throwable $e) {
            $this->logger->warning('Could not notify the inviter of an external collaborator', [
                'grantId' => $grant->getId(),
                'exception' => $e,
            ]);
        }
    }

    private function projectUrl(int $projectId): string
    {
        try {
            return $this->urlGenerator->linkToRouteAbsolute('projectcreatoraio.page.newProject', ['projectId' => $projectId]);
        } catch (Throwable) {
            return $this->urlGenerator->getAbsoluteURL('/');
        }
    }

    private function daysFrom(\DateTime $date, int $days): \DateTime
    {
        return (clone $date)->modify(sprintf('%+d days', $days));
    }

    private function now(): \DateTime
    {
        return $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'));
    }
}
