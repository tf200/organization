<?php

declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<ExternalInvite> */
class ExternalInviteMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'organization_external_invites', ExternalInvite::class);
    }

    public function findByTokenHash(string $tokenHash): ?ExternalInvite
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($tokenHash)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Marks every open link of the user as used, so only the newest one works.
     */
    public function invalidateOpenForUser(string $userUid, \DateTime $now): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('used_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE))
            ->where($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
            ->andWhere($qb->expr()->isNull('used_at'))
            ->executeStatement();
    }

    public function deleteForUser(string $userUid): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
            ->executeStatement();
    }
}
