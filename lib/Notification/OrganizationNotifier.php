<?php

declare(strict_types=1);

namespace OCA\Organization\Notification;

use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;
use OCP\IURLGenerator;

final class OrganizationNotifier implements INotifier
{
    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string
    {
        return NotificationConstants::APP_ID;
    }

    public function getName(): string
    {
        return 'Organization';
    }

    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== NotificationConstants::APP_ID) {
            throw new UnknownNotificationException();
        }

        $l10n = $this->l10nFactory->get(NotificationConstants::APP_ID, $languageCode);

        $subject = $notification->getSubject();
        $parameters = $notification->getSubjectParameters();

        $organizationName = isset($parameters['orgName']) ? (string) $parameters['orgName'] : '';

        $notification->setIcon(
            $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->imagePath(NotificationConstants::APP_ID, 'organization.svg')
            )
        );
        $notification->setLink(
            $this->urlGenerator->linkToRouteAbsolute('organization.Page.index')
        );

        switch ($subject) {
            case NotificationConstants::SUBJECT_SUBSCRIPTION_STATUS_CHANGED: {
                $newStatus = isset($parameters['newStatus']) ? (string) $parameters['newStatus'] : '';
                $notification->setParsedSubject(
                    $l10n->t('Subscription status changed for %s: %s', [$organizationName, $newStatus])
                );
                return $notification;
            }

            case NotificationConstants::SUBJECT_SUBSCRIPTION_EXTENDED: {
                $newEndedAt = isset($parameters['newEndedAt']) ? (string) $parameters['newEndedAt'] : '';
                $notification->setParsedSubject(
                    $l10n->t('Subscription extended for %s until %s', [$organizationName, $newEndedAt])
                );
                return $notification;
            }

            case NotificationConstants::SUBJECT_SUBSCRIPTION_EXPIRED: {
                $endedAt = isset($parameters['endedAt']) ? (string) $parameters['endedAt'] : '';
                $notification->setParsedSubject(
                    $l10n->t('Subscription expired for %s (ended %s)', [$organizationName, $endedAt])
                );
                return $notification;
            }

            case NotificationConstants::SUBJECT_ORGANIZATION_MEMBER_ADDED: {
                $memberDisplayName = isset($parameters['memberDisplayName']) ? (string) $parameters['memberDisplayName'] : '';
                $notification->setParsedSubject(
                    $l10n->t('%s was added to %s', [$memberDisplayName, $organizationName])
                );
                return $notification;
            }

            case NotificationConstants::SUBJECT_ORGANIZATION_MEMBER_REMOVED: {
                $memberDisplayName = isset($parameters['memberDisplayName']) ? (string) $parameters['memberDisplayName'] : '';
                $notification->setParsedSubject(
                    $l10n->t('%s was removed from %s', [$memberDisplayName, $organizationName])
                );
                return $notification;
            }

            case NotificationConstants::SUBJECT_ORGANIZATION_HANDOVER_STARTED: {
                $sourceDisplayName = isset($parameters['sourceDisplayName']) ? (string) $parameters['sourceDisplayName'] : '';
                $targetDisplayName = isset($parameters['targetDisplayName']) ? (string) $parameters['targetDisplayName'] : '';
                $notification->setParsedSubject(
                    $l10n->t('Account handover started in %s: %s → %s', [$organizationName, $sourceDisplayName, $targetDisplayName])
                );
                return $notification;
            }

            case NotificationConstants::SUBJECT_ORGANIZATION_HANDOVER_COMPLETED: {
                $sourceDisplayName = isset($parameters['sourceDisplayName']) ? (string) $parameters['sourceDisplayName'] : '';
                $targetDisplayName = isset($parameters['targetDisplayName']) ? (string) $parameters['targetDisplayName'] : '';
                $notification->setParsedSubject(
                    $l10n->t('Account handover completed in %s: %s → %s', [$organizationName, $sourceDisplayName, $targetDisplayName])
                );
                return $notification;
            }

            case NotificationConstants::SUBJECT_ORGANIZATION_HANDOVER_FAILED: {
                $sourceDisplayName = isset($parameters['sourceDisplayName']) ? (string) $parameters['sourceDisplayName'] : '';
                $targetDisplayName = isset($parameters['targetDisplayName']) ? (string) $parameters['targetDisplayName'] : '';
                $notification->setParsedSubject(
                    $l10n->t('Account handover failed in %s: %s → %s', [$organizationName, $sourceDisplayName, $targetDisplayName])
                );
                return $notification;
            }

            case NotificationConstants::SUBJECT_STORAGE_THRESHOLD: {
                $resourceName = isset($parameters['resourceName']) ? (string) $parameters['resourceName'] : '';
                $resourceType = isset($parameters['resourceType']) ? (string) $parameters['resourceType'] : '';
                $threshold = isset($parameters['threshold']) ? (int) $parameters['threshold'] : 0;
                $label = $resourceType === 'project' ? $l10n->t('Project') : $l10n->t('User');
                $notification->setParsedSubject(
                    $l10n->t('%1$s storage for %2$s in %3$s has reached %4$s%%', [$label, $resourceName, $organizationName, $threshold])
                );
                return $notification;
            }

            case NotificationConstants::SUBJECT_EXTERNAL_ACCESS_EXPIRING: {
                $notification->setParsedSubject(
                    $l10n->t('Access of %1$s to %2$s ends on %3$s', [
                        (string) ($parameters['externalName'] ?? ''),
                        (string) ($parameters['projectName'] ?? ''),
                        (string) ($parameters['expiresAt'] ?? ''),
                    ])
                );
                $this->linkToProject($notification, $parameters);
                return $notification;
            }

            case NotificationConstants::SUBJECT_EXTERNAL_ACCESS_ENDED: {
                $notification->setParsedSubject(
                    $l10n->t('Access of %1$s to %2$s has ended', [
                        (string) ($parameters['externalName'] ?? ''),
                        (string) ($parameters['projectName'] ?? ''),
                    ])
                );
                $this->linkToProject($notification, $parameters);
                return $notification;
            }
        }

        throw new UnknownNotificationException();
    }

    /**
     * @param array<string,mixed> $parameters
     */
    private function linkToProject(INotification $notification, array $parameters): void
    {
        $projectId = (int) ($parameters['projectId'] ?? 0);
        if ($projectId <= 0) {
            return;
        }

        try {
            $notification->setLink($this->urlGenerator->linkToRouteAbsolute('projectcreatoraio.page.newProject', ['projectId' => $projectId]));
        } catch (\Throwable) {
            // The project app is not installed; the organization page link stays.
        }
    }
}
