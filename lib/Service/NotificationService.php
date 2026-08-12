<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCP\Defaults;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\Headers\AutoSubmitted;
use OCP\Mail\IMailer;
use OCP\Notification\IManager;

use OCA\Organization\Db\UserMapper;
use OCA\Organization\Notification\NotificationConstants;

use DateTime;
use DateTimeZone;
use Psr\Log\LoggerInterface;

final class NotificationService
{
    public function __construct(
        private Defaults $defaults,
        private IFactory $l10nFactory,
        private IMailer $mailer,
        private IManager $notificationManager,
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
        private UserMapper $userMapper,
        private LoggerInterface $logger,
    ) {
    }

    public function notifySubscriptionStatusChanged(
        int $organizationId,
        string $organizationName,
        string $oldStatus,
        string $newStatus,
        ?string $actorUid,
    ): void {
        $this->notifyOrganizationMembers(
            $organizationId,
            $actorUid,
            NotificationConstants::SUBJECT_SUBSCRIPTION_STATUS_CHANGED,
            [
                'orgId' => $organizationId,
                'orgName' => $organizationName,
                'oldStatus' => $oldStatus,
                'newStatus' => $newStatus,
            ],
        );
    }

    public function notifySubscriptionExtended(
        int $organizationId,
        string $organizationName,
        string $newEndedAt,
        ?string $actorUid,
    ): void {
        $this->notifyOrganizationMembers(
            $organizationId,
            $actorUid,
            NotificationConstants::SUBJECT_SUBSCRIPTION_EXTENDED,
            [
                'orgId' => $organizationId,
                'orgName' => $organizationName,
                'newEndedAt' => $newEndedAt,
            ],
        );
    }

    public function notifySubscriptionExpired(
        int $organizationId,
        string $organizationName,
        ?string $endedAt,
    ): void {
        $this->notifyOrganizationMembers(
            $organizationId,
            null,
            NotificationConstants::SUBJECT_SUBSCRIPTION_EXPIRED,
            [
                'orgId' => $organizationId,
                'orgName' => $organizationName,
                'endedAt' => $endedAt,
            ],
        );
    }

    public function notifyOrganizationMemberAdded(
        int $organizationId,
        string $organizationName,
        string $memberUserId,
        ?string $memberDisplayName,
        ?string $actorUid,
    ): void {
        $this->notifyOrganizationMembers(
            $organizationId,
            $actorUid,
            NotificationConstants::SUBJECT_ORGANIZATION_MEMBER_ADDED,
            [
                'orgId' => $organizationId,
                'orgName' => $organizationName,
                'memberUserId' => $memberUserId,
                'memberDisplayName' => $memberDisplayName ?? $memberUserId,
            ],
        );

        $this->sendOrganizationMemberAddedEmail(
            $organizationId,
            $organizationName,
            $memberUserId,
            $memberDisplayName,
            $actorUid,
        );
    }

    public function notifyOrganizationMemberRemoved(
        int $organizationId,
        string $organizationName,
        string $memberUserId,
        ?string $memberDisplayName,
        ?string $actorUid,
    ): void {
        $this->notifyOrganizationMembers(
            $organizationId,
            $actorUid,
            NotificationConstants::SUBJECT_ORGANIZATION_MEMBER_REMOVED,
            [
                'orgId' => $organizationId,
                'orgName' => $organizationName,
                'memberUserId' => $memberUserId,
                'memberDisplayName' => $memberDisplayName ?? $memberUserId,
            ],
        );
    }

    public function notifyOrganizationHandoverStarted(
        int $organizationId,
        string $organizationName,
        string $sourceUserId,
        ?string $sourceDisplayName,
        string $targetUserId,
        ?string $targetDisplayName,
        ?string $actorUid,
    ): void {
        $this->notifyOrganizationMembers(
            $organizationId,
            $actorUid,
            NotificationConstants::SUBJECT_ORGANIZATION_HANDOVER_STARTED,
            [
                'orgId' => $organizationId,
                'orgName' => $organizationName,
                'sourceUserId' => $sourceUserId,
                'sourceDisplayName' => $sourceDisplayName ?? $sourceUserId,
                'targetUserId' => $targetUserId,
                'targetDisplayName' => $targetDisplayName ?? $targetUserId,
            ],
        );
    }

    public function notifyOrganizationHandoverCompleted(
        int $organizationId,
        string $organizationName,
        string $sourceUserId,
        ?string $sourceDisplayName,
        string $targetUserId,
        ?string $targetDisplayName,
        ?string $actorUid,
    ): void {
        $this->notifyOrganizationMembers(
            $organizationId,
            $actorUid,
            NotificationConstants::SUBJECT_ORGANIZATION_HANDOVER_COMPLETED,
            [
                'orgId' => $organizationId,
                'orgName' => $organizationName,
                'sourceUserId' => $sourceUserId,
                'sourceDisplayName' => $sourceDisplayName ?? $sourceUserId,
                'targetUserId' => $targetUserId,
                'targetDisplayName' => $targetDisplayName ?? $targetUserId,
            ],
        );
    }

    public function notifyOrganizationHandoverFailed(
        int $organizationId,
        string $organizationName,
        string $sourceUserId,
        ?string $sourceDisplayName,
        string $targetUserId,
        ?string $targetDisplayName,
        ?string $actorUid,
    ): void {
        $this->notifyOrganizationMembers(
            $organizationId,
            $actorUid,
            NotificationConstants::SUBJECT_ORGANIZATION_HANDOVER_FAILED,
            [
                'orgId' => $organizationId,
                'orgName' => $organizationName,
                'sourceUserId' => $sourceUserId,
                'sourceDisplayName' => $sourceDisplayName ?? $sourceUserId,
                'targetUserId' => $targetUserId,
                'targetDisplayName' => $targetDisplayName ?? $targetUserId,
            ],
        );
    }

    /** @param string[] $adminUserIds */
    public function notifyStorageThreshold(
        array $adminUserIds,
        int $organizationId,
        string $organizationName,
        string $resourceType,
        string $resourceId,
        string $resourceName,
        int $threshold,
    ): bool {
        $dateTime = new DateTime('now', new DateTimeZone('UTC'));
        $notified = false;
        foreach ($adminUserIds as $userId) {
            try {
                $notification = $this->notificationManager->createNotification();
                $notification->setApp(NotificationConstants::APP_ID)
                    ->setUser($userId)
                    ->setDateTime($dateTime)
                    ->setObject(NotificationConstants::OBJECT_TYPE_STORAGE, $resourceType . ':' . $resourceId)
                    ->setSubject(NotificationConstants::SUBJECT_STORAGE_THRESHOLD, [
                        'orgName' => $organizationName,
                        'resourceType' => $resourceType,
                        'resourceName' => $resourceName,
                        'threshold' => $threshold,
                    ])
                    ->setLink($this->urlGenerator->linkToRouteAbsolute('organization.Page.index'));
                $this->notificationManager->notify($notification);
                $notified = true;
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send storage threshold notification', [
                    'orgId' => $organizationId,
                    'userId' => $userId,
                    'resourceType' => $resourceType,
                    'resourceName' => $resourceName,
                    'threshold' => $threshold,
                    'exception' => $e,
                ]);
            }
        }

        return $notified;
    }

    /**
     * @param array<string,mixed> $subjectParameters
     */
    private function notifyOrganizationMembers(
        int $organizationId,
        ?string $actorUid,
        string $subject,
        array $subjectParameters,
    ): void {
        $members = $this->userMapper->getOrganizationMembers($organizationId);
        if ($members === []) {
            return;
        }

        $dateTime = new DateTime('now', new DateTimeZone('UTC'));
        $link = $this->urlGenerator->linkToRouteAbsolute('organization.Page.index');

        foreach ($members as $member) {
            $userId = (string) $member['user_uid'];
            if ($actorUid !== null && $userId === $actorUid) {
                continue;
            }

            try {
                $notification = $this->notificationManager->createNotification();
                $notification->setApp(NotificationConstants::APP_ID);
                $notification->setUser($userId);
                $notification->setDateTime($dateTime);
                $notification->setObject(NotificationConstants::OBJECT_TYPE_ORGANIZATION, (string) $organizationId);
                $notification->setSubject($subject, $subjectParameters);
                $notification->setLink($link);

                $this->notificationManager->notify($notification);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send notification', [
                    'app' => NotificationConstants::APP_ID,
                    'subject' => $subject,
                    'orgId' => $organizationId,
                    'userId' => $userId,
                    'exception' => $e,
                ]);
            }
        }
    }

    private function sendOrganizationMemberAddedEmail(
        int $organizationId,
        string $organizationName,
        string $memberUserId,
        ?string $memberDisplayName,
        ?string $actorUid,
    ): void {
        $member = $this->userManager->get($memberUserId);
        if ($member === null) {
            return;
        }

        $email = $member->getEMailAddress();
        if ($email === null || trim($email) === '') {
            return;
        }

        $languageCode = $this->l10nFactory->getUserLanguage($member);
        $l10n = $this->l10nFactory->get(NotificationConstants::APP_ID, $languageCode);
        $link = $this->urlGenerator->linkToRouteAbsolute('organization.Page.index');

        $emailTemplate = $this->mailer->createEMailTemplate('organization.MemberAdded', [
            'link' => $link,
            'organizationId' => $organizationId,
            'organizationName' => $organizationName,
            'memberUserId' => $memberUserId,
        ]);

        $instanceName = $this->defaults->getName();
        $resolvedMemberDisplayName = $memberDisplayName ?? $member->getDisplayName() ?? $memberUserId;
        $actorDisplayName = $this->resolveUserDisplayName($actorUid);

        $emailTemplate->setSubject($l10n->t('You were added to %s on %s', [$organizationName, $instanceName]));
        $emailTemplate->addHeader();
        $emailTemplate->addHeading($l10n->t('You were added to %s', [$organizationName]));
        $emailTemplate->addBodyText(
            $l10n->t('Hello %s,', [$resolvedMemberDisplayName])
        );
        $emailTemplate->addBodyText(
            $l10n->t('Your account has been added to the organization %s on %s.', [$organizationName, $instanceName])
        );

        if ($actorDisplayName !== null && $actorDisplayName !== '') {
            $emailTemplate->addBodyText(
                $l10n->t('Added by: %s', [$actorDisplayName])
            );
        }

        $emailTemplate->addBodyButton(
            $l10n->t('Open Organizations'),
            $link,
            false,
        );
        $emailTemplate->addFooter('', $languageCode);

        try {
            $message = $this->mailer->createMessage();
            $message->setTo([$email => $resolvedMemberDisplayName]);
            $message->useTemplate($emailTemplate);
            $message->setAutoSubmitted(AutoSubmitted::VALUE_AUTO_GENERATED);

            $this->mailer->send($message);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send organization member added email', [
                'app' => NotificationConstants::APP_ID,
                'orgId' => $organizationId,
                'memberUserId' => $memberUserId,
                'exception' => $e,
            ]);
        }
    }

    private function resolveUserDisplayName(?string $userId): ?string
    {
        if ($userId === null || trim($userId) === '') {
            return null;
        }

        $user = $this->userManager->get($userId);
        if ($user === null) {
            return $userId;
        }

        return $user->getDisplayName();
    }
}
