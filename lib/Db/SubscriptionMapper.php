<?php
declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class SubscriptionMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'subscriptions', Subscription::class);
    }

    /**
     * Finds the subscription for a given organization ID.
     * @param int $organizationId
     * @return Subscription|null
     */
    public function findByOrganizationId(int $organizationId): ?Subscription
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'organization_id',
                    $qb->createNamedParameter($organizationId)
                )
            );

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Finds an active subscription for a given organization ID.
     */
    public function findActiveSubscriptionForOrganization(int $organizationId): ?Subscription
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId)))
            ->andWhere(
                $qb->expr()->gt('ended_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
            )
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Finds all active subscriptions that have passed their end date
     * and updates their status to 'expired'.
     *
     * @return Subscription[] The subscriptions that were expired (previous state).
     */
    public function invalidateExpiredSubscriptions(): array
    {
        $now = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $findQuery = $this->db->getQueryBuilder();
        $findQuery->select(
            'id',
            'organization_id',
            'plan_id',
            'status',
            'started_at',
            'ended_at',
            'paused_at',
            'cancelled_at',
        )
            ->from('subscriptions')
            ->where($findQuery->expr()->eq('status', $findQuery->createNamedParameter('active')))
            ->andWhere($findQuery->expr()->isNotNull('ended_at'))
            ->andWhere($findQuery->expr()->lt('ended_at', $findQuery->createNamedParameter($now)));

        $result = $findQuery->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        if ($rows === []) {
            return [];
        }

        $expiredSubscriptionIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);

        $updateQuery = $this->db->getQueryBuilder();
        $updateQuery->update('subscriptions')
            ->set('status', $updateQuery->createNamedParameter('expired'))
            ->where($updateQuery->expr()->in('id', $updateQuery->createParameter('ids')));

        $updateQuery->setParameter('ids', $expiredSubscriptionIds, IQueryBuilder::PARAM_INT_ARRAY);

        $updateQuery->executeStatement();

        return array_map(static function (array $row): Subscription {
            $subscription = new Subscription();

            $subscription->setId((int) $row['id']);
            $subscription->setOrganizationId((int) $row['organization_id']);
            $subscription->setPlanId((int) $row['plan_id']);
            $subscription->setStatus((string) $row['status']);
            $subscription->setStartedAt((string) $row['started_at']);
            $subscription->setEndedAt($row['ended_at'] !== null ? (string) $row['ended_at'] : null);
            $subscription->setPausedAt($row['paused_at'] !== null ? (string) $row['paused_at'] : null);
            $subscription->setCancelledAt($row['cancelled_at'] !== null ? (string) $row['cancelled_at'] : null);

            return $subscription;
        }, $rows);
    }

    /**
     * @return int[]
     */
    public function findOrganizationIdsByPlanId(int $planId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('organization_id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('plan_id', $qb->createNamedParameter($planId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $organizationIds = array_map('intval', $result->fetchFirstColumn());
        $result->closeCursor();

        return $organizationIds;
    }
}
