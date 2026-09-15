<?php

declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\IDBConnection;

class TeamMemberMapper
{
    public function __construct(private IDBConnection $db)
    {
    }

    /** @return string[] */
    public function getUserIds(int $teamId, int $organizationId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_uid')->from('organization_team_members')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->orderBy('created_at', 'ASC');
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return array_map(static fn (array $row): string => (string) $row['user_uid'], $rows);
    }

    public function add(int $teamId, int $organizationId, string $userId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('organization_team_members')->values([
            'team_id' => $qb->createNamedParameter($teamId, \PDO::PARAM_INT),
            'organization_id' => $qb->createNamedParameter($organizationId, \PDO::PARAM_INT),
            'user_uid' => $qb->createNamedParameter($userId),
            'created_at' => $qb->createNamedParameter(gmdate('Y-m-d H:i:s')),
        ])->executeStatement();
    }

    public function remove(int $teamId, int $organizationId, string $userId): int
    {
        $qb = $this->db->getQueryBuilder();
        return $qb->delete('organization_team_members')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($userId)))
            ->executeStatement();
    }

    public function removeUser(string $userId, int $organizationId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('organization_team_members')
            ->where($qb->expr()->eq('user_uid', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->executeStatement();
    }

    public function removeTeam(int $teamId, int $organizationId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('organization_team_members')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->executeStatement();
    }

    public function hasMember(int $teamId, int $organizationId, string $userId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('id')->from('organization_team_members')
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_uid', $qb->createNamedParameter($userId)))
            ->setMaxResults(1)->executeQuery();
        $exists = $result->fetchOne() !== false;
        $result->closeCursor();
        return $exists;
    }
}
