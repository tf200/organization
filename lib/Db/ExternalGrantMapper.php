<?php

declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<ExternalGrant> */
class ExternalGrantMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'organization_project_externals', ExternalGrant::class);
    }

    public function findByProjectAndUser(int $projectId, string $userUid): ?ExternalGrant
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function findById(int $id): ?ExternalGrant
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /** @return ExternalGrant[] */
    public function findByProject(int $projectId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->orderBy('invited_at', 'DESC');
        return $this->findEntities($qb);
    }

    /**
     * @param string[] $statuses
     * @return ExternalGrant[]
     */
    public function findByOrganization(int $organizationId, array $statuses): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->in('status', $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY)))
            ->orderBy('invited_at', 'DESC');
        return $this->findEntities($qb);
    }

    /**
     * @param string[] $statuses
     * @return ExternalGrant[]
     */
    public function findByUser(string $userUid, array $statuses): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
            ->andWhere($qb->expr()->in('status', $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY)));
        return $this->findEntities($qb);
    }

    /**
     * Distinct externals holding a seat in the organization: those with a
     * pending or active grant on any of its projects.
     */
    public function countSeatHolders(int $organizationId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(DISTINCT ' . $qb->getColumnName('user_uid') . ')'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
                [ExternalGrant::STATUS_PENDING, ExternalGrant::STATUS_ACTIVE],
                IQueryBuilder::PARAM_STR_ARRAY,
            )));

        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    public function holdsSeat(int $organizationId, string $userUid): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from($this->getTableName())
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
            ->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
                [ExternalGrant::STATUS_PENDING, ExternalGrant::STATUS_ACTIVE],
                IQueryBuilder::PARAM_STR_ARRAY,
            )))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $found = $result->fetchOne() !== false;
        $result->closeCursor();
        return $found;
    }

    /**
     * Active grants ending after $now and no later than $until that have not
     * been warned about yet.
     *
     * @return ExternalGrant[]
     */
    public function findToWarn(\DateTime $now, \DateTime $until): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(ExternalGrant::STATUS_ACTIVE)))
            ->andWhere($qb->expr()->isNull('warned_at'))
            ->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
            ->andWhere($qb->expr()->lte('expires_at', $qb->createNamedParameter($until, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
        return $this->findEntities($qb);
    }

    /**
     * Pending or active grants whose end date has passed.
     *
     * @return ExternalGrant[]
     */
    public function findDue(\DateTime $now): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->in('status', $qb->createNamedParameter(
                [ExternalGrant::STATUS_PENDING, ExternalGrant::STATUS_ACTIVE],
                IQueryBuilder::PARAM_STR_ARRAY,
            )))
            ->andWhere($qb->expr()->isNotNull('expires_at'))
            ->andWhere($qb->expr()->lte('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
        return $this->findEntities($qb);
    }

    /**
     * Invitations that were never accepted and were sent before $before.
     *
     * @return ExternalGrant[]
     */
    public function findStalePending(\DateTime $before): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(ExternalGrant::STATUS_PENDING)))
            ->andWhere($qb->expr()->lte('invited_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
        return $this->findEntities($qb);
    }

    /**
     * Grants that were in use, ended before $before, and whose private folder
     * has not been handed to the project owner yet.
     *
     * @return ExternalGrant[]
     */
    public function findFoldersToRelease(\DateTime $before): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->in('status', $qb->createNamedParameter(
                [ExternalGrant::STATUS_EXPIRED, ExternalGrant::STATUS_REVOKED],
                IQueryBuilder::PARAM_STR_ARRAY,
            )))
            ->andWhere($qb->expr()->isNotNull('accepted_at'))
            ->andWhere($qb->expr()->isNull('folder_released_at'))
            ->andWhere($qb->expr()->lte('revoked_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
        return $this->findEntities($qb);
    }
}
