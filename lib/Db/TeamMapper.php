<?php

declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class TeamMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'organization_teams', Team::class);
    }

    public function findByIdAndOrganization(int $teamId, int $organizationId): ?Team
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($teamId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function findByOrganizationAndName(int $organizationId, string $name): ?Team
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)))
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /** @return Team[] */
    public function findByOrganization(int $organizationId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->orderBy('name', 'ASC');
        return $this->findEntities($qb);
    }
}
