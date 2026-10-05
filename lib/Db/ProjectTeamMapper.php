<?php

declare(strict_types=1);

namespace OCA\Organization\Db;

use OCP\IDBConnection;

class ProjectTeamMapper
{
    public function __construct(private IDBConnection $db)
    {
    }

    /** @return array{project_id:int,project_name:string}|null */
    public function findProject(int $organizationId, int $projectId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id AS project_id', 'name AS project_name')
            ->from('custom_projects')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($projectId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetch();
        if ($row === false) {
            return null;
        }
        return ['project_id' => (int) $row['project_id'], 'project_name' => (string) $row['project_name']];
    }

    /** @return array<int,array<string,mixed>> */
    public function findByOrganization(int $organizationId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('cp.id AS project_id', 'cp.name AS project_name', 'pt.id AS assignment_id', 'pt.team_id', 't.name AS team_name', 'pt.created_by', 'pt.created_at', 'pt.updated_at')
            ->from('custom_projects', 'cp')
            ->leftJoin('cp', 'organization_project_teams', 'pt', $qb->expr()->andX(
                $qb->expr()->eq('pt.project_id', 'cp.id'),
                $qb->expr()->eq('pt.organization_id', 'cp.organization_id'),
            ))
            ->leftJoin('pt', 'organization_teams', 't', $qb->expr()->eq('t.id', 'pt.team_id'))
            ->where($qb->expr()->eq('cp.organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->orderBy('cp.name', 'ASC');
        $rows = $qb->executeQuery()->fetchAll();
        return array_map(static fn (array $row): array => [
            'projectId' => (int) $row['project_id'],
            'projectName' => (string) $row['project_name'],
            'team' => $row['assignment_id'] === null ? null : [
                'id' => (int) $row['team_id'],
                'name' => (string) $row['team_name'],
            ],
            'assignmentId' => $row['assignment_id'] === null ? null : (int) $row['assignment_id'],
            'createdBy' => $row['created_by'] === null ? null : (string) $row['created_by'],
            'createdAt' => $row['created_at'] === null ? null : (string) $row['created_at'],
            'updatedAt' => $row['updated_at'] === null ? null : (string) $row['updated_at'],
        ], $rows);
    }

    public function findTeamIdForProject(int $organizationId, int $projectId): ?int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('team_id')
            ->from('organization_project_teams')
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, \PDO::PARAM_INT)))
            ->setMaxResults(1);
        $teamId = $qb->executeQuery()->fetchOne();
        return $teamId === false ? null : (int) $teamId;
    }

    public function assign(int $organizationId, int $projectId, int $teamId, string $createdBy, string $now): void
    {
        $this->db->beginTransaction();
        try {
            $delete = $this->db->getQueryBuilder();
            $delete->delete('organization_project_teams')
                ->where($delete->expr()->eq('organization_id', $delete->createNamedParameter($organizationId, \PDO::PARAM_INT)))
                ->andWhere($delete->expr()->eq('project_id', $delete->createNamedParameter($projectId, \PDO::PARAM_INT)))
                ->executeStatement();

            $insert = $this->db->getQueryBuilder();
            $insert->insert('organization_project_teams')->values([
                'organization_id' => $insert->createNamedParameter($organizationId, \PDO::PARAM_INT),
                'project_id' => $insert->createNamedParameter($projectId, \PDO::PARAM_INT),
                'team_id' => $insert->createNamedParameter($teamId, \PDO::PARAM_INT),
                'created_by' => $insert->createNamedParameter($createdBy),
                'created_at' => $insert->createNamedParameter($now),
                'updated_at' => $insert->createNamedParameter($now),
            ])->executeStatement();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function unassign(int $organizationId, int $projectId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('organization_project_teams')
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, \PDO::PARAM_INT)))
            ->executeStatement();
    }

    public function removeForTeam(int $organizationId, int $teamId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('organization_project_teams')
            ->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, \PDO::PARAM_INT)))
            ->executeStatement();
    }

    public function removeForProject(int $projectId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('organization_project_teams')
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, \PDO::PARAM_INT)))
            ->executeStatement();
    }
}
