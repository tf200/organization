<?php

declare(strict_types=1);

namespace OCA\Organization\Controller;

use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;

use OCA\Organization\Service\PlanEntitlementValidator;
use OCA\Organization\Service\TrialOrganizationService;

use Psr\Log\LoggerInterface;

class AdminSettingsController extends OCSController
{
	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private LoggerInterface $logger,
		private TrialOrganizationService $trialService,
		private PlanEntitlementValidator $entitlementValidator,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Retrieve current trial settings.
	 *
	 * @return DataResponse
	 */
	public function getTrialSettings(): DataResponse
	{
		return new DataResponse($this->trialService->getAllSettings());
	}

	/**
	 * Save trial organization settings.
	 *
	 * @param string $trial_duration
	 * @param int $trial_max_members
	 * @param int $trial_max_projects
	 * @param float $trial_shared_storage_gb
	 * @param float $trial_private_storage_gb
	 * @param string $trial_plan_name
	 * @return DataResponse
	 */
	#[PasswordConfirmationRequired]
	public function saveTrialSettings(
		string $trial_duration = '7 days',
		int $trial_max_members = 3,
		int $trial_max_projects = 1,
		float $trial_shared_storage_gb = 0.1,
		float $trial_private_storage_gb = 0.1,
		string $trial_plan_name = 'Trial Plan',
	): DataResponse {
		if ($trial_max_members < 1) {
			throw new OCSException('Max members must be at least 1', 104);
		}
		if ($trial_max_projects < 1) {
			throw new OCSException('Max projects must be at least 1', 104);
		}
		if (trim($trial_duration) === '') {
			throw new OCSException('Trial duration is required', 104);
		}
		if (trim($trial_plan_name) === '') {
			throw new OCSException('Trial plan name is required', 104);
		}
		if (!is_finite($trial_shared_storage_gb) || !is_finite($trial_private_storage_gb)) {
			throw new OCSException('Storage limits must be finite numbers', 104);
		}

		$sharedStorageBytes = (int) round($trial_shared_storage_gb * 1073741824);
		$privateStorageBytes = (int) round($trial_private_storage_gb * 1073741824);
		$this->entitlementValidator->validate(
			$trial_max_members,
			$trial_max_projects,
			$sharedStorageBytes,
			$privateStorageBytes,
		);

		$this->config->setAppValue('organization', 'trial_duration', trim($trial_duration));
		$this->config->setAppValue('organization', 'trial_max_members', (string) $trial_max_members);
		$this->config->setAppValue('organization', 'trial_max_projects', (string) $trial_max_projects);
		$this->config->setAppValue('organization', 'trial_shared_storage_per_project', (string) $sharedStorageBytes);
		$this->config->setAppValue('organization', 'trial_private_storage_per_user', (string) $privateStorageBytes);
		$this->config->setAppValue('organization', 'trial_plan_name', trim($trial_plan_name));

		$this->logger->info('Trial organization settings updated');

		return new DataResponse([
			'saved' => true,
		]);
	}
}
