<?php

declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\IDBConnection;

final class StorageThresholdMapper
{
    public function __construct(private IDBConnection $db)
    {
    }

    /**
     * @return array<int,array{user_uid:string,organization_id:int,organization_name:string}>
     */
    public function getOrganizationUsers(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('om.user_uid', 'om.organization_id', 'o.name AS organization_name')
            ->from('organization_members', 'om')
            ->innerJoin('om', 'organizations', 'o', $qb->expr()->eq('om.organization_id', 'o.id'));

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_map(static fn (array $row): array => [
            'user_uid' => (string) $row['user_uid'],
            'organization_id' => (int) $row['organization_id'],
            'organization_name' => (string) $row['organization_name'],
        ], $rows);
    }

    /**
     * Project usage is the size cached on the project folder's filecache entry.
     *
     * @return array<int,array{project_id:int,project_name:string,group_folder_id:int,organization_id:int,organization_name:string,usage:int}>
     */
    public function getProjects(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            'cp.id AS project_id',
            'cp.name AS project_name',
            'cp.group_folder_id',
            'cp.organization_id',
            'o.name AS organization_name',
            'fc.size AS usage',
        )
            ->from('custom_projects', 'cp')
            ->innerJoin('cp', 'organizations', 'o', $qb->expr()->eq('cp.organization_id', 'o.id'))
            ->innerJoin('cp', 'filecache', 'fc', $qb->expr()->eq('cp.folder_id', 'fc.fileid'))
            ->where($qb->expr()->isNotNull('cp.folder_id'))
            ->andWhere($qb->expr()->isNotNull('cp.group_folder_id'))
            ->andWhere($qb->expr()->gte('fc.size', $qb->createNamedParameter(0, \PDO::PARAM_INT)));

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_map(static fn (array $row): array => [
            'project_id' => (int) $row['project_id'],
            'project_name' => (string) $row['project_name'],
            'group_folder_id' => (int) $row['group_folder_id'],
            'organization_id' => (int) $row['organization_id'],
            'organization_name' => (string) $row['organization_name'],
            'usage' => (int) $row['usage'],
        ], $rows);
    }

    /** @return string[] */
    public function getAdminUserIds(int $organizationId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_uid')
            ->from('organization_members')
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('role', $qb->createNamedParameter('admin')));

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_values(array_unique(array_map(static fn (array $row): string => (string) $row['user_uid'], $rows)));
    }

    public function getThreshold(int $organizationId, string $resourceType, string $resourceId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('threshold')
            ->from('org_storage_thresholds')
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('resource_type', $qb->createNamedParameter($resourceType)))
            ->andWhere($qb->expr()->eq('resource_id', $qb->createNamedParameter($resourceId)));

        $result = $qb->executeQuery();
        $threshold = $result->fetchOne();
        $result->closeCursor();

        return $threshold === false ? 0 : (int) $threshold;
    }

    public function setThreshold(int $organizationId, string $resourceType, string $resourceId, int $threshold): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $qb = $this->db->getQueryBuilder();
        $qb->update('org_storage_thresholds')
            ->set('threshold', $qb->createNamedParameter($threshold, \PDO::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter($now))
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('resource_type', $qb->createNamedParameter($resourceType)))
            ->andWhere($qb->expr()->eq('resource_id', $qb->createNamedParameter($resourceId)));

        if ($qb->executeStatement() > 0) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert('org_storage_thresholds')->values([
            'organization_id' => $qb->createNamedParameter($organizationId, \PDO::PARAM_INT),
            'resource_type' => $qb->createNamedParameter($resourceType),
            'resource_id' => $qb->createNamedParameter($resourceId),
            'threshold' => $qb->createNamedParameter($threshold, \PDO::PARAM_INT),
            'updated_at' => $qb->createNamedParameter($now),
        ])->executeStatement();
    }
}
