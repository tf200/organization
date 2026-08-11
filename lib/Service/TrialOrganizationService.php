<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use DateTime;
use DateTimeZone;
use DateInterval;

use OCP\AppFramework\OCS\OCSException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

use OCA\Organization\Db\Organization;
use OCA\Organization\Db\OrganizationMapper;
use OCA\Organization\Db\PlanMapper;
use OCA\Organization\Db\Subscription;
use OCA\Organization\Db\SubscriptionMapper;

class TrialOrganizationService
{
	public const TRIAL_DURATION = 'trial_duration';
	public const TRIAL_MAX_MEMBERS = 'trial_max_members';
	public const TRIAL_MAX_PROJECTS = 'trial_max_projects';
	public const TRIAL_SHARED_STORAGE_PER_PROJECT = 'trial_shared_storage_per_project';
	public const TRIAL_PRIVATE_STORAGE_PER_USER = 'trial_private_storage_per_user';
	public const TRIAL_PRICE = 'trial_price';
	public const TRIAL_CURRENCY = 'trial_currency';
	public const TRIAL_PLAN_NAME = 'trial_plan_name';

	public function __construct(
		private IConfig $config,
		private OrganizationMapper $organizationMapper,
		private OrganizationService $organizationService,
		private PlanMapper $planMapper,
		private PlanEntitlementValidator $entitlementValidator,
		private SubscriptionMapper $subscriptionMapper,
		private OrganizationAdminService $organizationAdminService,
		private LoggerInterface $logger,
	) {
	}

	public function getDuration(): string
	{
		return $this->config->getAppValue('organization', self::TRIAL_DURATION, '7 days');
	}

	public function getMaxMembers(): int
	{
		return (int) $this->config->getAppValue('organization', self::TRIAL_MAX_MEMBERS, '3');
	}

	public function getMaxProjects(): int
	{
		return (int) $this->config->getAppValue('organization', self::TRIAL_MAX_PROJECTS, '1');
	}

	public function getSharedStoragePerProject(): int
	{
		return (int) $this->config->getAppValue('organization', self::TRIAL_SHARED_STORAGE_PER_PROJECT, '104857600');
	}

	public function getPrivateStoragePerUser(): int
	{
		return (int) $this->config->getAppValue('organization', self::TRIAL_PRIVATE_STORAGE_PER_USER, '107374182');
	}

	public function getPrice(): float
	{
		return (float) $this->config->getAppValue('organization', self::TRIAL_PRICE, '0');
	}

	public function getCurrency(): string
	{
		return $this->config->getAppValue('organization', self::TRIAL_CURRENCY, 'EUR');
	}

	public function getPlanName(): string
	{
		return $this->config->getAppValue('organization', self::TRIAL_PLAN_NAME, 'Trial Plan');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getAllSettings(): array
	{
		return [
			'duration' => $this->getDuration(),
			'maxMembers' => $this->getMaxMembers(),
			'maxProjects' => $this->getMaxProjects(),
			'sharedStoragePerProject' => $this->getSharedStoragePerProject(),
			'privateStoragePerUser' => $this->getPrivateStoragePerUser(),
			'price' => $this->getPrice(),
			'currency' => $this->getCurrency(),
			'planName' => $this->getPlanName(),
		];
	}

	/**
	 * Creates a trial organization with a custom trial plan and subscription.
	 *
	 * @return array{organization: Organization, subscription: Subscription}
	 */
	public function createTrialOrganization(
		string $displayName,
		?string $contactFirstName,
		?string $contactLastName,
		?string $contactEmail,
		?string $contactPhone,
		string $adminUserId,
		string $adminPassword,
		?string $adminDisplayName,
		?string $adminEmail,
	): array {
		$organization = $this->organizationService->createOrganization(
			$displayName,
			$contactFirstName,
			$contactLastName,
			$contactEmail,
			$contactPhone,
			trim($adminUserId),
			'trial',
		);

		if ($organization === null) {
			throw new OCSException('Failed to create trial organization', 104);
		}

		$this->entitlementValidator->validate(
			$this->getMaxMembers(),
			$this->getMaxProjects(),
			$this->getSharedStoragePerProject(),
			$this->getPrivateStoragePerUser(),
		);

		$plan = $this->planMapper->create(
			$this->getPlanName() . ' — ' . $displayName,
			$this->getMaxMembers(),
			$this->getMaxProjects(),
			$this->getSharedStoragePerProject(),
			$this->getPrivateStoragePerUser(),
			$this->getPrice(),
			$this->getCurrency(),
			false,
		);

		$now = new DateTime('now', new DateTimeZone('UTC'));
		$validityDuration = DateInterval::createFromDateString($this->getDuration());
		if ($validityDuration === false) {
			$this->logger->error('Invalid trial duration configured: ' . $this->getDuration());
			throw new OCSException('Invalid trial duration configured', 104);
		}
		$endedAt = (clone $now)->add($validityDuration);

		$subscription = new Subscription();
		$subscription->setOrganizationId($organization->getId());
		$subscription->setPlanId($plan->getId());
		$subscription->setStatus('active');
		$subscription->setStartedAt($now->format('Y-m-d H:i:s'));
		$subscription->setEndedAt($endedAt->format('Y-m-d H:i:s'));

		$subscription = $this->subscriptionMapper->insert($subscription);

		$this->organizationAdminService->createOrganizationAdmin(
			$organization->getId(),
			trim($adminUserId),
			$adminPassword,
			$adminDisplayName,
			$adminEmail ?? $contactEmail,
		);

		return [
			'organization' => $organization,
			'subscription' => $subscription,
		];
	}
}
