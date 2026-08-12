<?php

declare(strict_types=1);

namespace OCA\Organization\Service;

use OCP\Files\IRootFolder;
use OCP\IServerContainer;

use OCA\Organization\Db\StorageThresholdMapper;

use Psr\Log\LoggerInterface;

final class StorageThresholdService
{
    public function __construct(
        private StorageThresholdMapper $mapper,
        private UserQuotaService $userQuotaService,
        private IRootFolder $rootFolder,
        private IServerContainer $serverContainer,
        private NotificationService $notificationService,
        private LoggerInterface $logger,
    ) {
    }

    public function check(): void
    {
        foreach ($this->mapper->getOrganizationUsers() as $user) {
            try {
                $quota = $this->userQuotaService->getEffectiveQuota($user['user_uid']);
                if ($quota === null || $quota <= 0) {
                    continue;
                }

                $usage = $this->rootFolder->getUserFolder($user['user_uid'])->getSize(false);
                if ($usage < 0) {
                    continue;
                }
                $this->process(
                    'user',
                    $user['user_uid'],
                    $user['organization_id'],
                    $user['organization_name'],
                    $user['user_uid'],
                    $usage,
                    $quota,
                );
            } catch (\Throwable $e) {
                $this->logger->error('Failed to check organization user storage', [
                    'userId' => $user['user_uid'],
                    'orgId' => $user['organization_id'],
                    'exception' => $e,
                ]);
            }
        }

        if (!class_exists(\OCA\GroupFolders\Folder\FolderManager::class)) {
            return;
        }

        try {
            $folderManager = $this->serverContainer->get(\OCA\GroupFolders\Folder\FolderManager::class);
        } catch (\Throwable $e) {
            $this->logger->warning('Group Folders is unavailable for project storage checks', ['exception' => $e]);
            return;
        }

        foreach ($this->mapper->getProjects() as $project) {
            try {
                $folder = $folderManager->getFolder($project['group_folder_id']);
                if ($folder === null || $folder->quota <= 0) {
                    continue;
                }

                $this->process(
                    'project',
                    (string) $project['project_id'],
                    $project['organization_id'],
                    $project['organization_name'],
                    $project['project_name'],
                    $project['usage'],
                    $folder->quota,
                );
            } catch (\Throwable $e) {
                $this->logger->error('Failed to check organization project storage', [
                    'projectId' => $project['project_id'],
                    'orgId' => $project['organization_id'],
                    'exception' => $e,
                ]);
            }
        }
    }

    public function determineThreshold(int $usage, int $quota): int
    {
        if ($quota <= 0) {
            return 0;
        }

        if ($usage >= $quota) {
            return 100;
        }

        return $usage >= $quota * 0.8 ? 80 : 0;
    }

    public function determineNotificationThreshold(int $previous, int $current): ?int
    {
        return $current > $previous ? $current : null;
    }

    private function process(
        string $resourceType,
        string $resourceId,
        int $organizationId,
        string $organizationName,
        string $resourceName,
        int $usage,
        int $quota,
    ): void {
        $previous = $this->mapper->getThreshold($organizationId, $resourceType, $resourceId);
        $current = $this->determineThreshold($usage, $quota);
        if ($current === $previous) {
            return;
        }

        $notificationThreshold = $this->determineNotificationThreshold($previous, $current);
        if ($notificationThreshold === null) {
            $this->mapper->setThreshold($organizationId, $resourceType, $resourceId, $current);
            return;
        }

        $notified = $this->notificationService->notifyStorageThreshold(
            $this->mapper->getAdminUserIds($organizationId),
            $organizationId,
            $organizationName,
            $resourceType,
            $resourceId,
            $resourceName,
            $notificationThreshold,
        );
        if ($notified) {
            $this->mapper->setThreshold($organizationId, $resourceType, $resourceId, $current);
        }
    }
}
