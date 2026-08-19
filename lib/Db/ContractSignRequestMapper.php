<?php

declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class ContractSignRequestMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'org_contract_sign_reqs', ContractSignRequest::class);
    }

    /** @return ContractSignRequest[] */
    public function findAllForContract(int $organizationId, int $contractId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('contract_id', $qb->createNamedParameter($contractId, \PDO::PARAM_INT)))
            ->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }

    public function findForContract(int $organizationId, int $contractId, int $requestId): ?ContractSignRequest
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('contract_id', $qb->createNamedParameter($contractId, \PDO::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function existsForContract(int $organizationId, int $contractId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from($this->getTableName())
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('contract_id', $qb->createNamedParameter($contractId, \PDO::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $exists = $result->fetchOne() !== false;
        $result->closeCursor();
        return $exists;
    }
}
