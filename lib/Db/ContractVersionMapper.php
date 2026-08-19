<?php

declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class ContractVersionMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'org_contract_versions', ContractVersion::class);
    }

    public function findLatest(int $contractId): ?ContractVersion
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('contract_id', $qb->createNamedParameter($contractId, \PDO::PARAM_INT)))
            ->orderBy('version_number', 'DESC')->setMaxResults(1);
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /** @return ContractVersion[] */
    public function findAll(int $contractId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('contract_id', $qb->createNamedParameter($contractId, \PDO::PARAM_INT)))
            ->orderBy('version_number', 'ASC');
        return $this->findEntities($qb);
    }
}
