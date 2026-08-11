<?php
declare(strict_types=1);

namespace OCA\Organization\Controller;

use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

use DateInterval;
use DateTime;
use DateTimeZone;

use OCA\Organization\Db\Organization;
use OCA\Organization\Db\OrganizationMapper;
use OCA\Organization\Db\PlanMapper;
use OCA\Organization\Db\SubscriptionHistoryMapper;
use OCA\Organization\Db\SubscriptionMapper;
use OCA\Organization\Db\UserMapper;
use OCA\Organization\Event\EntitlementsChangedEvent;
use OCA\Organization\Service\AccountHandoverService;
use OCA\Organization\Service\NotificationService;
use OCA\Organization\Service\OrganizationAdminService;
use OCA\Organization\Service\OrganizationService;
use OCA\Organization\Service\PlanEntitlementValidator;
use OCA\Organization\Service\SubscriptionService;
use OCA\Organization\Service\TrialOrganizationService;

use Exception;
use Psr\Log\LoggerInterface;

class OrganizationController extends OCSController
{

    public function __construct(
        string $appName,
        IRequest $request,
        private OrganizationMapper $organizationMapper,
        private SubscriptionMapper $subscriptionMapper,
        private PlanMapper $planMapper,
        private UserMapper $userMapper,
        private OrganizationService $organizationService,
        private OrganizationAdminService $organizationAdminService,
        private AccountHandoverService $accountHandoverService,
        private NotificationService $notificationService,
        private PlanEntitlementValidator $entitlementValidator,
        private SubscriptionService $subscriptionService,
        private TrialOrganizationService $trialOrganizationService,
        private SubscriptionHistoryMapper $subscriptionHistoryMapper,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private IDBConnection $db,
        private IEventDispatcher $eventDispatcher,
        private LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Get all organizations with subscription details.
     *
     * @param string $search Search query
     * @param int|null $limit Limit results
     * @param int $offset Offset for pagination
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function getOrganizations(string $search = '', ?int $limit = null, int $offset = 0): DataResponse
    {
        $organizationId = $this->resolveOrganizationForCurrentUser(true);

        // Query organizations directly (organization-centric, no N+1 queries)
        $orgRows = $this->organizationMapper->findAll($search, $limit, $offset, $organizationId);

        $organizations = [];
        foreach ($orgRows as $row) {
            $organizations[] = [
                'id' => (int) $row['id'],
                'displayname' => $row['name'],
                'usercount' => (int) ($row['user_count'] ?? 0),
                'disabled' => 0,
                'canAdd' => true,
                'canRemove' => true,
                'isOrganization' => true,
                'type' => $row['type'] ?? 'standard',
                'adminUid' => $row['admin_uid'] ?? null,
                'subscription' => [
                    'id' => $row['subscription_id'],
                    'status' => $row['subscription_status'],
                    'startedAt' => $row['started_at'],
                    'endedAt' => $row['ended_at'],
                    'planId' => $row['plan_id'],
                    'planName' => $row['plan_name'],
                    'maxMembers' => $row['max_members'],
                    'maxProjects' => $row['max_projects'],
                ],
            ];
        }

        return new DataResponse(['organizations' => $organizations]);
    }

    /**
     * Get organization details including subscription and plan.
     *
     * @param int $organizationId ID of the organization
     * @return DataResponse
     * @throws OCSNotFoundException
     */
    #[NoAdminRequired]
    public function getOrganization(int $organizationId): DataResponse
    {
        $this->assertCanManageOrganization($organizationId, true);

        $organization = $this->organizationMapper->find($organizationId);
        if ($organization === null) {
            throw new OCSNotFoundException('Organization does not exist');
        }

        $subscription = $this->subscriptionMapper->findByOrganizationId($organization->getId());
        if ($subscription === null) {
            throw new OCSNotFoundException('No active subscription found for this organization');
        }

        $plan = $this->planMapper->find($subscription->getPlanId());
        if ($plan === null) {
            throw new OCSNotFoundException('The plan associated with this subscription could not be found');
        }

        return new DataResponse([
            'organization' => $organization,
            'subscription' => $subscription,
            'plan' => $plan,
            'members' => $this->buildMembersPayload($organization->getId()),
        ]);
    }

    /**
     * Update editable organization metadata.
     */
    #[NoAdminRequired]
    public function updateOrganization(
        int $organizationId,
        string $displayname,
        ?string $contactFirstName = null,
        ?string $contactLastName = null,
        ?string $contactEmail = null,
        ?string $contactPhone = null
    ): DataResponse {
        $this->assertCanManageOrganization($organizationId, true);

        $organization = $this->organizationMapper->find($organizationId);
        if ($organization === null) {
            throw new OCSNotFoundException('Organization does not exist');
        }

        $organization->setName($displayname);
        $organization->setContactFirstName($contactFirstName);
        $organization->setContactLastName($contactLastName);
        $organization->setContactEmail($contactEmail);
        $organization->setContactPhone($contactPhone);

        $updated = $this->organizationMapper->update($organization);

        return new DataResponse(['organization' => $updated]);
    }

    /**
     * Return organization members for management UI.
     */
    #[NoAdminRequired]
    public function getOrganizationMembers(int $organizationId): DataResponse
    {
        $this->assertCanManageOrganization($organizationId, true);

        return new DataResponse([
            'members' => $this->buildMembersPayload($organizationId),
        ]);
    }

    /**
     * Search for users available to add to this organization.
     *
     * @param int $organizationId ID of the organization
     * @param string $search Search query for user display name, user id or email
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function searchAvailableUsers(int $organizationId, string $search = ''): DataResponse
    {
        $this->assertCanManageOrganization($organizationId, true);

        $search = trim($search);
        if ($search === '') {
            return new DataResponse(['users' => []]);
        }

        $searchLower = strtolower($search);
        $availableUsers = [];

        // Get users already in this organization to exclude them
        $existingMembers = $this->userMapper->getOrganizationMembers($organizationId);
        $existingUids = array_column($existingMembers, 'user_uid');

        // Search all users in the system
        $limit = 20;
        $offset = 0;

        do {
            $users = $this->userManager->searchDisplayName($search, $limit, $offset);
            foreach ($users as $user) {
                $uid = $user->getUID();

                // Skip if already in this organization
                if (in_array($uid, $existingUids, true)) {
                    continue;
                }

                // Check if user already belongs to another organization
                $membership = $this->userMapper->getOrganizationMembership($uid);
                if ($membership !== null) {
                    continue;
                }

                $displayName = $user->getDisplayName();
                $email = $user->getEMailAddress();

                // Additional matching for user ID and email
                $matchesSearch = (
                    stripos($uid, $searchLower) !== false ||
                    stripos(strtolower($displayName), $searchLower) !== false ||
                    ($email !== null && stripos(strtolower($email), $searchLower) !== false)
                );

                if ($matchesSearch) {
                    $availableUsers[] = [
                        'uid' => $uid,
                        'displayName' => $displayName,
                        'email' => $email,
                    ];
                }
            }
            $offset += $limit;
        } while (count($users) === $limit && count($availableUsers) < 10);

        // Limit results
        $availableUsers = array_slice($availableUsers, 0, 10);

        return new DataResponse(['users' => $availableUsers]);
    }

    #[NoAdminRequired]
    public function addOrganizationMember(int $organizationId, string $userId): DataResponse
    {
        $this->assertCanManageOrganization($organizationId, true);

        $organization = $this->getOrganizationOrThrow($organizationId);

        $userId = trim($userId);
        if ($userId === '') {
            throw new OCSException('User ID is required', 104);
        }

        $user = $this->userManager->get($userId);
        if ($user === null) {
            throw new OCSNotFoundException('User does not exist');
        }

        $existingMembership = $this->userMapper->getOrganizationMembership($userId);
        if ($existingMembership !== null && $existingMembership['organization_id'] !== $organizationId) {
            throw new OCSException('User already belongs to another organization', 104);
        }

        if ($existingMembership !== null && $existingMembership['organization_id'] === $organizationId) {
            return new DataResponse([
                'members' => $this->buildMembersPayload($organizationId),
            ]);
        }

        $this->assertMemberCapacityAvailable($organizationId);

        $this->userMapper->addOrganizationToUser($userId, $organizationId, 'member');
        $this->notificationService->notifyOrganizationMemberAdded(
            $organizationId,
            $organization->getName(),
            $userId,
            $user->getDisplayName(),
            $this->userSession->getUser()?->getUID(),
        );

        return new DataResponse([
            'members' => $this->buildMembersPayload($organizationId),
        ]);
    }

    #[NoAdminRequired]
    public function createOrganizationUser(
        int $organizationId,
        string $userId,
        string $password,
        ?string $displayName = null,
        ?string $email = null
    ): DataResponse {
        $this->assertCanManageOrganization($organizationId, true);

        $organization = $this->getOrganizationOrThrow($organizationId);

        $userId = trim($userId);
        if ($userId === '' || trim($password) === '') {
            throw new OCSException('User ID and password are required', 104);
        }

        $existingMembership = $this->userMapper->getOrganizationMembership($userId);
        if ($existingMembership !== null && $existingMembership['organization_id'] !== $organizationId) {
            throw new OCSException('User already belongs to another organization', 104);
        }

        if ($this->userManager->userExists($userId)) {
            throw new OCSException('User ID already exists', 104);
        }

        try {
            $this->userManager->validateUserId($userId);
        } catch (\InvalidArgumentException $e) {
            throw new OCSException('Invalid user ID: ' . $e->getMessage(), 104);
        }
        $this->assertMemberCapacityAvailable($organizationId);

        $user = $this->userManager->createUser($userId, $password);
        if ($user === false) {
            throw new OCSException('Failed to create user account', 104);
        }

        try {
            if ($displayName !== null && trim($displayName) !== '') {
                $user->setDisplayName(trim($displayName));
            }
            if ($email !== null && trim($email) !== '') {
                $user->setEMailAddress(trim($email));
            }

            $this->userMapper->addOrganizationToUser($userId, $organizationId, 'member');
            $this->notificationService->notifyOrganizationMemberAdded(
                $organizationId,
                $organization->getName(),
                $userId,
                $user->getDisplayName(),
                $this->userSession->getUser()?->getUID(),
            );
        } catch (\Throwable $e) {
            $user->delete();
            throw new OCSException('Failed to assign user to organization: ' . $e->getMessage(), 104);
        }

        return new DataResponse([
            'members' => $this->buildMembersPayload($organizationId),
            'createdUser' => [
                'uid' => $userId,
                'displayName' => $user->getDisplayName(),
                'email' => $user->getEMailAddress(),
            ],
        ]);
    }

    #[NoAdminRequired]
    public function removeOrganizationMember(int $organizationId, string $userId): DataResponse
    {
        $this->assertCanManageOrganization($organizationId, true);

        $organization = $this->getOrganizationOrThrow($organizationId);

        if ($organization->getAdminUid() === $userId) {
            throw new OCSException('Cannot remove organization admin from members', 104);
        }

        $currentUser = $this->userSession->getUser();
        if ($currentUser !== null && $currentUser->getUID() === $userId) {
            throw new OCSException('You cannot remove yourself from the organization', 104);
        }

        $existingMembership = $this->userMapper->getOrganizationMembership($userId);
        if ($existingMembership === null || $existingMembership['organization_id'] !== $organizationId) {
            throw new OCSNotFoundException('Organization member not found');
        }

        $member = $this->userManager->get($userId);
        $this->userMapper->removeUserFromOrganization($userId);
        $this->notificationService->notifyOrganizationMemberRemoved(
            $organizationId,
            $organization->getName(),
            $userId,
            $member?->getDisplayName(),
            $this->userSession->getUser()?->getUID(),
        );

        return new DataResponse([
            'members' => $this->buildMembersPayload($organizationId),
        ]);
    }

    #[NoAdminRequired]
    public function handoverOrganizationMember(
        int $organizationId,
        string $sourceUserId,
        string $targetUserId,
        bool $dryRun = false,
        bool $removeSourceFromGroups = false,
        bool $remapDeckContent = true,
    ): DataResponse {
        $this->assertCanManageOrganization($organizationId, true);

        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        try {
            $idempotencyKey = $this->request->getHeader('Idempotency-Key');

            $sourceUid = trim($sourceUserId);
            $targetUid = trim($targetUserId);

            if ($sourceUid === '' || $targetUid === '') {
                throw new \InvalidArgumentException('sourceUserId and targetUserId are required');
            }
            $result = $this->accountHandoverService->createAndRun(
                $organizationId,
                $sourceUid,
                $targetUid,
                $currentUser->getUID(),
                $dryRun,
                $removeSourceFromGroups,
                $remapDeckContent,
                $idempotencyKey,
            );

            return new DataResponse($result);
        } catch (\InvalidArgumentException $e) {
            throw new OCSException($e->getMessage(), 104);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to start account handover', [
                'exception' => $e,
                'organizationId' => $organizationId,
                'sourceUserId' => $sourceUserId,
                'targetUserId' => $targetUserId,
                'requestedByUserId' => $currentUser->getUID(),
                'dryRun' => $dryRun,
            ]);

            throw new OCSException('Failed to start account handover', 104);
        }
    }

    #[NoAdminRequired]
    public function getHandoverJob(int $organizationId, int $jobId): DataResponse
    {
        $this->assertCanManageOrganization($organizationId, true);

        try {
            return new DataResponse($this->accountHandoverService->getJob($organizationId, $jobId));
        } catch (\InvalidArgumentException $e) {
            throw new OCSNotFoundException($e->getMessage());
        }
    }

    #[NoAdminRequired]
    public function listHandoverJobs(int $organizationId, string $status = '', int $limit = 20, int $offset = 0): DataResponse
    {
        $this->assertCanManageOrganization($organizationId, true);

        return new DataResponse($this->accountHandoverService->listJobs($organizationId, $status, $limit, $offset));
    }

    #[NoAdminRequired]
    public function retryHandoverJob(int $organizationId, int $jobId, bool $failedStepsOnly = true): DataResponse
    {
        $this->assertCanManageOrganization($organizationId, true);

        try {
            $this->accountHandoverService->getJob($organizationId, $jobId);
            return new DataResponse($this->accountHandoverService->retryJob($jobId, $failedStepsOnly));
        } catch (\InvalidArgumentException $e) {
            throw new OCSNotFoundException($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to retry account handover job', [
                'exception' => $e,
                'organizationId' => $organizationId,
                'jobId' => $jobId,
            ]);
            throw new OCSException('Failed to retry account handover job', 104);
        }
    }

    #[NoAdminRequired]
    public function listHandoverEvents(int $organizationId, int $jobId, int $limit = 50, int $offset = 0): DataResponse
    {
        $this->assertCanManageOrganization($organizationId, true);

        try {
            return new DataResponse($this->accountHandoverService->listEvents($organizationId, $jobId, $limit, $offset));
        } catch (\InvalidArgumentException $e) {
            throw new OCSNotFoundException($e->getMessage());
        }
    }

    /**
     * Create a new organization with subscription.
     *
     * @param string $validity Duration string (e.g., "1 year")
     * @param int|null $memberLimit Max members
     * @param int|null $projectsLimit Max projects
     * @param int|null $sharedStoragePerProject Shared storage in bytes
     * @param int|null $privateStorage Private storage in bytes
     * @param int|null $planId Plan ID (null for custom plan)
     * @param float|null $price Price
     * @param string|null $currency Currency code
     * @param string|null $displayname Display name
     * @param string|null $contactFirstName First name of contact person
     * @param string|null $contactLastName Last name of contact person
     * @param string|null $contactEmail Email of contact person
     * @param string|null $contactPhone Phone of contact person
     * @param string|null $adminUserId Organization admin user ID
     * @param string|null $adminPassword Organization admin password
     * @param string|null $adminDisplayName Organization admin display name
     * @param string|null $adminEmail Organization admin email
     * @return DataResponse
     * @throws OCSException
     */
    #[PasswordConfirmationRequired]
    public function createOrganization(
        string $validity,
        ?int $memberLimit,
        ?int $projectsLimit,
        ?int $sharedStoragePerProject,
        ?int $privateStorage,
        ?int $planId,
        ?float $price,
        ?string $currency = 'EUR',
        ?string $displayname = '',
        ?string $contactFirstName = null,
        ?string $contactLastName = null,
        ?string $contactEmail = null,
        ?string $contactPhone = null,
        ?string $adminUserId = null,
        ?string $adminPassword = null,
        ?string $adminDisplayName = null,
        ?string $adminEmail = null,
        bool $trial = false,
    ): DataResponse {
        if ($trial) {
            return $this->createTrialOrganization(
                $displayname,
                $contactFirstName,
                $contactLastName,
                $contactEmail,
                $contactPhone,
                $adminUserId,
                $adminPassword,
                $adminDisplayName,
                $adminEmail,
            );
        }

        $adminCreated = false;

        try {
            $this->db->beginTransaction();

            if ($adminUserId === null || trim($adminUserId) === '' || $adminPassword === null || trim($adminPassword) === '') {
                throw new OCSException('Organization admin user ID and password are required', 104);
            }

            $organization = $this->organizationService->createOrganization(
                $displayname ?? '',
                $contactFirstName,
                $contactLastName,
                $contactEmail,
                $contactPhone,
                trim($adminUserId)
            );

            if ($organization === null) {
                throw new OCSException('Failed to create organization', 104);
            }

            $subscription = $this->subscriptionService->createSubscription(
                $organization->getId(),
                $validity,
                $planId,
                $memberLimit,
                $projectsLimit,
                $sharedStoragePerProject,
                $privateStorage,
                $price,
                $currency
            );

            $this->organizationAdminService->createOrganizationAdmin(
                $organization->getId(),
                trim($adminUserId),
                $adminPassword,
                $adminDisplayName,
                $adminEmail ?? $contactEmail
            );
            $adminCreated = true;

            $this->notificationService->notifyOrganizationMemberAdded(
                $organization->getId(),
                $organization->getName(),
                trim($adminUserId),
                $adminDisplayName,
                $this->userSession->getUser()?->getUID(),
            );

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            if ($adminCreated && $adminUserId !== null) {
                $createdUser = $this->userManager->get(trim($adminUserId));
                if ($createdUser !== null) {
                    $createdUser->delete();
                }
            }
            throw new OCSException('Failed to create organization: ' . $e->getMessage(), 104);
        }

        return new DataResponse($subscription);
    }

    /**
     * Creates a trial organization with pre-configured limits and duration.
     */
    private function createTrialOrganization(
        ?string $displayname,
        ?string $contactFirstName,
        ?string $contactLastName,
        ?string $contactEmail,
        ?string $contactPhone,
        ?string $adminUserId,
        ?string $adminPassword,
        ?string $adminDisplayName,
        ?string $adminEmail,
    ): DataResponse {
        if ($adminUserId === null || trim($adminUserId) === '' || $adminPassword === null || trim($adminPassword) === '') {
            throw new OCSException('Organization admin user ID and password are required', 104);
        }

        try {
            $this->db->beginTransaction();

            $result = $this->trialOrganizationService->createTrialOrganization(
                $displayname ?? '',
                $contactFirstName,
                $contactLastName,
                $contactEmail,
                $contactPhone,
                $adminUserId,
                $adminPassword,
                $adminDisplayName,
                $adminEmail,
            );

            $this->db->commit();

            try {
                $organization = $result['organization'];
                $this->notificationService->notifyOrganizationMemberAdded(
                    $organization->getId(),
                    $organization->getName(),
                    trim($adminUserId),
                    $adminDisplayName,
                    $this->userSession->getUser()?->getUID(),
                );
            } catch (\Exception $e) {
                $this->logger->error('Failed to send trial creation notification', [
                    'exception' => $e,
                    'organizationId' => $organization->getId(),
                ]);
            }

            return new DataResponse($result);
        } catch (\Exception $e) {
            $this->db->rollBack();
            if ($adminUserId !== null) {
                $createdUser = $this->userManager->get(trim($adminUserId));
                if ($createdUser !== null) {
                    $createdUser->delete();
                }
            }
            throw new OCSException('Failed to create trial organization: ' . $e->getMessage(), 104);
        }
    }

    /**
     * Update an organization's subscription.
     *
     * @param int $organizationId The organization ID
     * @param string $displayName New display name
     * @param int $planId Plan ID
     * @param int $maxMembers Max members
     * @param int $maxProjects Max projects
     * @param int $sharedStoragePerProject Shared storage in bytes
     * @param int $privateStoragePerUser Private storage in bytes
     * @param string $status Subscription status
     * @param string|null $extendDuration Duration to extend
     * @param float|null $price Price
     * @param string|null $currency Currency code
     * @return DataResponse
     * @throws OCSException
     */
    #[PasswordConfirmationRequired]
    public function updateSubscription(
        int $organizationId,
        string $displayName,
        int $planId,
        int $maxMembers,
        int $maxProjects,
        int $sharedStoragePerProject,
        int $privateStoragePerUser,
        string $status,
        ?string $extendDuration = null,
        ?float $price = null,
        ?string $currency = null
    ): DataResponse {
        $this->db->beginTransaction();
        try {
            $updatedSubscription = $this->subscriptionService->updateSubscription(
                $organizationId,
                $displayName,
                $planId,
                $maxMembers,
                $maxProjects,
                $sharedStoragePerProject,
                $privateStoragePerUser,
                $status,
                $extendDuration,
                $price,
                $currency,
                $this->userSession->getUser()->getUID()
            );

            $this->db->commit();

        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Failed to update organization: ' . $e->getMessage(), ['exception' => $e]);
            if ($e instanceof OCSNotFoundException) {
                throw $e;
            }
            throw new OCSException('Failed to update organization: ' . $e->getMessage());
        }

        $this->dispatchEntitlementChange($organizationId);
        return new DataResponse(['subscription' => $updatedSubscription]);
    }

    /**
     * Converts a trial organization to a standard organization with a new plan and subscription.
     */
    #[PasswordConfirmationRequired]
    public function convertTrialToStandard(
        int $organizationId,
        int $planId,
        string $validity,
        ?string $adminUserId = null,
    ): DataResponse {
        $this->assertCanManageOrganization($organizationId, true);

        $organization = $this->organizationMapper->find($organizationId);
        if ($organization === null) {
            throw new OCSNotFoundException('Organization does not exist');
        }

        if ($organization->getType() !== 'trial') {
            throw new OCSException('Only trial organizations can be converted', 104);
        }

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

        $this->db->beginTransaction();
        try {
            $organization->setType('standard');
            $this->organizationMapper->update($organization);

            $subscription = $this->subscriptionMapper->findByOrganizationId($organizationId);
            if ($subscription === null) {
                throw new OCSNotFoundException('No subscription found for this organization');
            }

            $previousSubscription = clone $subscription;

            $now = new DateTime('now', new DateTimeZone('UTC'));
            $validityDuration = DateInterval::createFromDateString($validity);
            if ($validityDuration === false) {
                throw new OCSException('Invalid validity duration', 104);
            }
            $endedAt = (clone $now)->add($validityDuration);

            $subscription->setPlanId($planId);
            $subscription->setStatus('active');
            $subscription->setStartedAt($now->format('Y-m-d H:i:s'));
            $subscription->setEndedAt($endedAt->format('Y-m-d H:i:s'));
            $subscription->setPausedAt(null);
            $subscription->setCancelledAt(null);
            $this->subscriptionMapper->update($subscription);

            $this->subscriptionHistoryMapper->createLog(
                $subscription,
                $previousSubscription,
                $adminUserId ?? $this->userSession->getUser()?->getUID() ?? 'system',
            );

            $this->db->commit();

            $this->dispatchEntitlementChange($organizationId);

            return new DataResponse([
                'organization' => $organization,
                'subscription' => $subscription,
            ]);
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Failed to convert trial organization', [
                'exception' => $e,
                'organizationId' => $organizationId,
            ]);
            if ($e instanceof OCSNotFoundException) {
                throw $e;
            }
            throw new OCSException('Failed to convert trial organization: ' . $e->getMessage());
        }
    }

    private function dispatchEntitlementChange(int $organizationId): void
    {
        try {
            $this->eventDispatcher->dispatchTyped(EntitlementsChangedEvent::forOrganization($organizationId));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to dispatch organization entitlement change', [
                'organizationId' => $organizationId,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Returns null for global admins, otherwise the organization id of current org admin user.
     */
    private function resolveOrganizationForCurrentUser(bool $mustBeOrgAdmin = true): ?int
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $userId = $user->getUID();
        if ($this->groupManager->isAdmin($userId)) {
            return null;
        }

        $membership = $this->userMapper->getOrganizationMembership($userId);
        if ($membership === null) {
            throw new OCSForbiddenException('You are not assigned to an organization');
        }

        if ($mustBeOrgAdmin && $membership['role'] !== 'admin') {
            throw new OCSForbiddenException('Only organization admins can access this resource');
        }

        return (int) $membership['organization_id'];
    }

    private function assertCanManageOrganization(int $organizationId, bool $mustBeOrgAdmin): void
    {
        $allowedOrganizationId = $this->resolveOrganizationForCurrentUser($mustBeOrgAdmin);
        if ($allowedOrganizationId !== null && $allowedOrganizationId !== $organizationId) {
            throw new OCSNotFoundException('Organization does not exist');
        }
    }

    private function assertMemberCapacityAvailable(int $organizationId): void
    {
        $subscription = $this->subscriptionMapper->findByOrganizationId($organizationId);
        if ($subscription === null) {
            throw new OCSNotFoundException('No subscription found for this organization');
        }

        $plan = $this->planMapper->find($subscription->getPlanId());
        if ($plan === null) {
            throw new OCSNotFoundException('No plan found for this organization');
        }

        $maxMembers = (int) $plan->getMaxMembers();
        $currentMembers = $this->userMapper->countUsersInOrganization($organizationId);

        if ($currentMembers >= $maxMembers) {
            throw new OCSForbiddenException('Organization member limit reached for current subscription plan');
        }
    }

    /**
     * @throws OCSNotFoundException
     */
    private function getOrganizationOrThrow(int $organizationId): Organization
    {
        $organization = $this->organizationMapper->find($organizationId);
        if ($organization === null) {
            throw new OCSNotFoundException('Organization does not exist');
        }

        return $organization;
    }

    /**
     * @return array<int,array{uid:string,displayName:string,email:?string,role:string}>
     */
    private function buildMembersPayload(int $organizationId): array
    {
        $members = [];
        foreach ($this->userMapper->getOrganizationMembers($organizationId) as $member) {
            $user = $this->userManager->get($member['user_uid']);
            $members[] = [
                'uid' => $member['user_uid'],
                'displayName' => $user?->getDisplayName() ?? $member['user_uid'],
                'email' => $user?->getEMailAddress(),
                'role' => $member['role'],
            ];
        }

        return $members;
    }
}
