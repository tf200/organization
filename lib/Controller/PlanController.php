<?php
declare(strict_types=1);

namespace OCA\Organization\Controller;

use Exception;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCA\Organization\Db\PlanMapper;
use OCA\Organization\Event\EntitlementsChangedEvent;
use OCA\Organization\Service\PlanService;
use Psr\Log\LoggerInterface;

class PlanController extends OCSController
{

    public function __construct(
        string $appName,
        IRequest $request,
        private PlanMapper $planMapper,
        private PlanService $planService,
        private IEventDispatcher $eventDispatcher,
        private LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Get all plans for admin management.
     *
     * @param string $search
     * @param int|null $limit
     * @param int $offset
     * @return DataResponse
     */
    public function getPlans(string $search = '', ?int $limit = null, int $offset = 0): DataResponse
    {
        $plans = $this->planService->getAllPlans($search, $limit, $offset);
        return new DataResponse(['plans' => $plans]);
    }

    /**
     * Get a single plan by ID.
     *
     * @param int $planId
     * @return DataResponse
     * @throws OCSNotFoundException
     */
    public function getPlan(int $planId): DataResponse
    {
        $plan = $this->planService->getPlan($planId);
        $subscriptionCount = $this->planMapper->countSubscriptions($planId);

        $data = $plan->jsonSerialize();
        $data['subscriptionCount'] = $subscriptionCount;

        return new DataResponse($data);
    }

    /**
     * Create a new plan.
     *
     * @param string $name
     * @param int $maxMembers
     * @param int $maxProjects
     * @param int $sharedStoragePerProject
     * @param int $privateStoragePerUser
     * @param float|null $price
     * @param string|null $currency
     * @param bool|null $isPublic
     * @return DataResponse
     * @throws OCSException
     */
    #[PasswordConfirmationRequired]
    public function createPlan(
        string $name,
        int $maxMembers,
        int $maxProjects,
        int $sharedStoragePerProject,
        int $privateStoragePerUser,
        ?float $price = null,
        ?string $currency = 'EUR',
        ?bool $isPublic = false
    ): DataResponse {
        try {
            $plan = $this->planService->createPlan(
                $name,
                $maxMembers,
                $maxProjects,
                $sharedStoragePerProject,
                $privateStoragePerUser,
                $price,
                $currency,
                $isPublic
            );
            return new DataResponse($plan);
        } catch (Exception $e) {
            $this->logger->error('Failed to create plan: ' . $e->getMessage(), ['exception' => $e]);
            throw new OCSException('Failed to create plan: ' . $e->getMessage());
        }
    }

    /**
     * Update an existing plan.
     *
     * @param int $planId
     * @param string $name
     * @param int $maxMembers
     * @param int $maxProjects
     * @param int $sharedStoragePerProject
     * @param int $privateStoragePerUser
     * @param float|null $price
     * @param string|null $currency
     * @param bool|null $isPublic
     * @return DataResponse
     * @throws OCSException
     */
    #[PasswordConfirmationRequired]
    public function updatePlan(
        int $planId,
        string $name,
        int $maxMembers,
        int $maxProjects,
        int $sharedStoragePerProject,
        int $privateStoragePerUser,
        ?float $price = null,
        ?string $currency = 'EUR',
        ?bool $isPublic = false
    ): DataResponse {
        try {
            $plan = $this->planService->updatePlan(
                $planId,
                $name,
                $maxMembers,
                $maxProjects,
                $sharedStoragePerProject,
                $privateStoragePerUser,
                $price,
                $currency,
                $isPublic
            );
            try {
                $this->eventDispatcher->dispatchTyped(EntitlementsChangedEvent::forPlan($planId));
            } catch (\Throwable $e) {
                $this->logger->error('Failed to dispatch plan entitlement change', [
                    'planId' => $planId,
                    'exception' => $e,
                ]);
            }
            return new DataResponse($plan);
        } catch (Exception $e) {
            $this->logger->error('Failed to update plan: ' . $e->getMessage(), ['exception' => $e]);
            if ($e instanceof OCSNotFoundException) {
                throw $e;
            }
            throw new OCSException('Failed to update plan: ' . $e->getMessage());
        }
    }

    /**
     * Delete a plan.
     *
     * @param int $planId
     * @return DataResponse
     * @throws OCSException
     */
    #[PasswordConfirmationRequired]
    public function deletePlan(int $planId): DataResponse
    {
        try {
            $this->planService->deletePlan($planId);
            return new DataResponse(['status' => 'success']);
        } catch (Exception $e) {
            $this->logger->error('Failed to delete plan: ' . $e->getMessage(), ['exception' => $e]);
            if ($e instanceof OCSException || $e instanceof OCSNotFoundException) {
                throw $e;
            }
            throw new OCSException('Failed to delete plan: ' . $e->getMessage());
        }
    }
}
