<?php

declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<External> */
class ExternalMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'organization_externals', External::class);
    }

    public function findByUserUid(string $userUid): ?External
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function findByEmail(string $email): ?External
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('email', $qb->createNamedParameter(mb_strtolower(trim($email)))));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * @param string[] $userUids
     * @return array<string,External> keyed by user UID
     */
    public function findByUserUids(array $userUids): array
    {
        $userUids = array_values(array_unique($userUids));
        if ($userUids === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->in('user_uid', $qb->createNamedParameter($userUids, IQueryBuilder::PARAM_STR_ARRAY)));

        $byUid = [];
        foreach ($this->findEntities($qb) as $external) {
            $byUid[$external->getUserUid()] = $external;
        }
        return $byUid;
    }

    /**
     * @param string[] $statuses
     * @return External[]
     */
    public function findByStatuses(array $statuses): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->in('status', $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY)));
        return $this->findEntities($qb);
    }
}
