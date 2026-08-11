<?php
declare(strict_types=1);

namespace OCA\Organization\Service;

use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCA\Organization\Db\Plan;
use OCA\Organization\Db\PlanMapper;

class PlanService
{

    public function __construct(
        private PlanMapper $planMapper,
        private PlanEntitlementValidator $entitlementValidator,
    ) {
    }

    /**
     * Get all plans for admin management.
     */
    public function getAllPlans(string $search, ?int $limit, int $offset): array
    {
        return $this->planMapper->findAllAdmin($search, $limit, $offset);
    }

    /**
     * Get a single plan by ID.
     *
     * @throws OCSNotFoundException
     */
    public function getPlan(int $id): Plan
    {
        $plan = $this->planMapper->find($id);
        if ($plan === null) {
            throw new OCSNotFoundException('Plan not found');
        }
        return $plan;
    }

    /**
     * Create a new plan.
     */
    public function createPlan(
        string $name,
        int $maxMembers,
        int $maxProjects,
        int $sharedStoragePerProject,
        int $privateStoragePerUser,
        ?float $price,
        ?string $currency,
        ?bool $isPublic
    ): Plan {
        $this->entitlementValidator->validate(
            $maxMembers,
            $maxProjects,
            $sharedStoragePerProject,
            $privateStoragePerUser,
        );

        return $this->planMapper->create(
            $name,
            $maxMembers,
            $maxProjects,
            $sharedStoragePerProject,
            $privateStoragePerUser,
            $price,
            $currency,
            $isPublic
        );
    }

    /**
     * Update an existing plan.
     *
     * @throws OCSNotFoundException
     */
    public function updatePlan(
        int $id,
        string $name,
        int $maxMembers,
        int $maxProjects,
        int $sharedStoragePerProject,
        int $privateStoragePerUser,
        ?float $price,
        ?string $currency,
        ?bool $isPublic
    ): Plan {
        $this->entitlementValidator->validate(
            $maxMembers,
            $maxProjects,
            $sharedStoragePerProject,
            $privateStoragePerUser,
        );

        $plan = $this->getPlan($id);
        $plan->setName($name);
        $plan->setMaxMembers($maxMembers);
        $plan->setMaxProjects($maxProjects);
        $plan->setSharedStoragePerProject($sharedStoragePerProject);
        $plan->setPrivateStoragePerUser($privateStoragePerUser);
        $plan->setPrice($price);
        $plan->setCurrency($currency);
        $plan->setIsPublic($isPublic);
        return $this->planMapper->update($plan);
    }

    /**
     * Delete a plan.
     *
     * @throws OCSException
     * @throws OCSNotFoundException
     */
    public function deletePlan(int $id): void
    {
        $count = $this->planMapper->countSubscriptions($id);
        if ($count > 0) {
            throw new OCSException('Cannot delete plan: it is used by ' . $count . ' subscriptions', 400);
        }
        $plan = $this->getPlan($id);
        $this->planMapper->delete($plan);
    }

    /**
     * Handles plan update logic - decides whether to update existing plan or create a new custom one.

     */
    public function handlePlanUpdate(
        int $newPlanId,
        int $originalPlanId,
        int $maxMembers,
        int $maxProjects,
        int $sharedStorage,
        int $privateStorage,
        ?float $price,
        ?string $currency,
        int $organizationId
    ): int {
        $originalPlan = $this->planMapper->find($originalPlanId);
        $newPlan = $this->planMapper->find($newPlanId);

        if ($originalPlan === null) {
            throw new OCSNotFoundException('Current plan not found');
        }

        // SCENARIO 1: Switching to a public plan
        if ($newPlan !== null && $newPlan->getIsPublic()) {
            $this->entitlementValidator->validate(
                $newPlan->getMaxMembers(),
                $newPlan->getMaxProjects(),
                $newPlan->getSharedStoragePerProject(),
                $newPlan->getPrivateStoragePerUser(),
            );
            return $newPlan->getId();
        }

        $this->entitlementValidator->validate(
            $maxMembers,
            $maxProjects,
            $sharedStorage,
            $privateStorage,
        );

        // SCENARIO 2: Editing an existing custom plan
        if (!$originalPlan->getIsPublic()) {
            $originalPlan->setMaxMembers($maxMembers);
            $originalPlan->setMaxProjects($maxProjects);
            $originalPlan->setSharedStoragePerProject($sharedStorage);
            $originalPlan->setPrivateStoragePerUser($privateStorage);
            $originalPlan->setPrice($price);
            $originalPlan->setCurrency($currency);
            $this->planMapper->update($originalPlan);
            return $originalPlan->getId();
        }

        // SCENARIO 3: Creating a new custom plan from a public plan
        $newCustomPlan = $this->planMapper->create(
            'Custom Plan for Org ' . $organizationId,
            $maxMembers,
            $maxProjects,
            $sharedStorage,
            $privateStorage,
            $price,
            $currency,
            false // Private plan
        );

        return $newCustomPlan->getId();
    }
}
