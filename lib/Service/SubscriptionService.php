<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCP\AppFramework\OCS\OCSNotFoundException;

use OCA\Organization\Db\OrganizationMapper;
use OCA\Organization\Db\Subscription;
use OCA\Organization\Db\SubscriptionMapper;
use OCA\Organization\Db\PlanMapper;
use OCA\Organization\Db\SubscriptionHistoryMapper;

use DateTime;
use DateTimeZone;

class SubscriptionService
{
    private SubscriptionMapper $subscriptionMapper;
    private PlanMapper $planMapper;
    private OrganizationMapper $organizationMapper;
    private PlanService $planService;
    private SubscriptionHistoryMapper $subscriptionHistoryMapper;
    private NotificationService $notificationService;

    public function __construct(
        SubscriptionMapper $subscriptionMapper,
        PlanMapper $planMapper,
        OrganizationMapper $organizationMapper,
        PlanService $planService,
        private PlanEntitlementValidator $entitlementValidator,
        SubscriptionHistoryMapper $subscriptionHistoryMapper,
        NotificationService $notificationService,
    ) {
        $this->subscriptionMapper = $subscriptionMapper;
        $this->planMapper = $planMapper;
        $this->organizationMapper = $organizationMapper;
        $this->planService = $planService;
        $this->subscriptionHistoryMapper = $subscriptionHistoryMapper;
        $this->notificationService = $notificationService;
    }

    /**
     * Creates a new subscription for an organization.
     */
    public function createSubscription(
        int $organizationId,
        string $validity,
        ?int $planId,
        ?int $memberLimit,
        ?int $projectsLimit,
        ?int $sharedStoragePerProject,
        ?int $privateStorage,
        ?float $price,
        ?string $currency
    ): Subscription {

        $subscription = new Subscription();

        $now = new DateTime('now', new DateTimeZone('UTC'));
        $validityDuration = \DateInterval::createFromDateString($validity);
        $endedAt = (clone $now)->add($validityDuration);

        $subscription->setOrganizationId($organizationId);
        $subscription->setStatus('active');
        $subscription->setStartedAt($now->format('Y-m-d H:i:s'));
        $subscription->setEndedAt($endedAt->format('Y-m-d H:i:s'));

        if ($planId === null) {
            $this->entitlementValidator->validate(
                $memberLimit,
                $projectsLimit,
                $sharedStoragePerProject,
                $privateStorage,
            );

            $plan = $this->planMapper->create(
                'Custom Plan for Org ' . $organizationId,
                $memberLimit,
                $projectsLimit,
                $sharedStoragePerProject,
                $privateStorage,
                $price,
                $currency,
                false
            );

            $planId = $plan->getId();
        } else {
            $plan = $this->planMapper->find($planId);
            if ($plan === null) {
                throw new OCSNotFoundException('Plan not found');
            }
            $this->entitlementValidator->validate(
                $plan->getMaxMembers(),
                $plan->getMaxProjects(),
                $plan->getSharedStoragePerProject(),
                $plan->getPrivateStoragePerUser(),
            );
        }

        $subscription->setPlanId($planId);

        return $this->subscriptionMapper->insert($subscription);
    }

    /**
     * Updates an organization's subscription.
     */
    public function updateSubscription(
        int $organizationId,
        string $displayName,
        int $newPlanId,
        int $maxMembers,
        int $maxProjects,
        int $sharedStoragePerProject,
        int $privateStoragePerUser,
        string $status,
        ?string $extendDuration,
        ?float $price,
        ?string $currency,
        string $changedByUserId
    ): Subscription {
        // 1. Find the organization and subscription
        $organization = $this->organizationMapper->find($organizationId);
        if ($organization === null) {
            throw new OCSNotFoundException('Organization does not exist');
        }

        $subscription = $this->subscriptionMapper->findByOrganizationId($organization->getId());
        if ($subscription === null) {
            throw new OCSNotFoundException('Active subscription for this organization does not exist');
        }

        // Keep copy for history
        $previousSubscription = clone $subscription;

        // 2. Update organization display name if changed
        if ($organization->getName() !== $displayName) {
            $organization->setName($displayName);
            $this->organizationMapper->update($organization);
        }

        // 3. Handle plan logic
        $finalPlanId = $this->planService->handlePlanUpdate(
            $newPlanId,
            $previousSubscription->getPlanId(),
            $maxMembers,
            $maxProjects,
            $sharedStoragePerProject,
            $privateStoragePerUser,
            $price,
            $currency,
            $organization->getId()
        );
        $subscription->setPlanId($finalPlanId);

        // 4. Handle status changes
        $now = new DateTime();
        $originalStatus = $previousSubscription->getStatus();
        $newStatus = $status;
        $statusChangeRequested = $originalStatus !== $newStatus;

        if ($statusChangeRequested) {
            switch ($newStatus) {
                case 'paused':
                    $subscription->setStatus('paused');
                    $subscription->setPausedAt($now->format('Y-m-d H:i:s'));
                    $subscription->setCancelledAt(null);
                    break;

                case 'cancelled':
                    $subscription->setStatus('cancelled');
                    $subscription->setCancelledAt($now->format('Y-m-d H:i:s'));
                    $subscription->setPausedAt(null);
                    break;

                case 'active':
                    $subscription->setStatus('active');
                    $subscription->setPausedAt(null);
                    $subscription->setCancelledAt(null);
                    break;
            }
        }

        $statusChanged = $statusChangeRequested && $originalStatus !== $subscription->getStatus();

        // 5. Handle duration extension
        $extended = false;
        if ($extendDuration !== null) {
            $currentEndedAt =
                $subscription->getEndedAt() ? new DateTime($subscription->getEndedAt()) : new DateTime();

            $newEndedAt = (clone $currentEndedAt)->modify('+' . $extendDuration);
            $subscription->setEndedAt($newEndedAt->format('Y-m-d H:i:s'));
            $extended = $previousSubscription->getEndedAt() !== $subscription->getEndedAt();
        }

        // 6. Save subscription
        $updatedSubscription = $this->subscriptionMapper->update($subscription);

        // 7. Create history log
        $this->subscriptionHistoryMapper->createLog(
            $updatedSubscription,
            $previousSubscription,
            $changedByUserId,
        );

        // 8. Delete old custom plan if no longer used
        $originalPlan = $this->planMapper->find($previousSubscription->getPlanId());
        if ($originalPlan !== null && !$originalPlan->getIsPublic() && $originalPlan->getId() !== $finalPlanId) {
            $this->planMapper->delete($originalPlan);
        }

        $organizationName = (string) ($organization->getName() ?? '');

        if ($statusChanged) {
            $this->notificationService->notifySubscriptionStatusChanged(
                $organization->getId(),
                $organizationName,
                (string) ($originalStatus ?? ''),
                (string) $subscription->getStatus(),
                $changedByUserId,
            );
        }

        if ($extended) {
            $this->notificationService->notifySubscriptionExtended(
                $organization->getId(),
                $organizationName,
                (string) ($subscription->getEndedAt() ?? ''),
                $changedByUserId,
            );
        }

        return $updatedSubscription;
    }
}
