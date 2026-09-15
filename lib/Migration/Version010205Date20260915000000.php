<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010205Date20260915000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('organization_teams')) {
            $table = $schema->createTable('organization_teams');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('organization_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false]);
            $table->addColumn('fte', Types::FLOAT, ['notnull' => true, 'default' => 1]);
            $table->addColumn('projects_per_fte', Types::FLOAT, ['notnull' => true, 'default' => 1]);
            $table->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['organization_id', 'name'], 'org_teams_org_name_uidx');
        }

        if (!$schema->hasTable('organization_team_members')) {
            $table = $schema->createTable('organization_team_members');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('team_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('organization_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('user_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['team_id', 'user_uid'], 'org_team_member_team_user_uidx');
            $table->addIndex(['organization_id', 'user_uid'], 'org_team_member_org_user_idx');
            $table->addIndex(['team_id'], 'org_team_member_team_idx');
        }

        if (!$schema->hasTable('organization_project_teams')) {
            $table = $schema->createTable('organization_project_teams');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('organization_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('project_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('team_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['project_id'], 'org_project_teams_project_uidx');
            $table->addIndex(['organization_id', 'project_id'], 'org_project_teams_org_project_idx');
            $table->addIndex(['organization_id', 'team_id'], 'org_project_teams_org_team_idx');
        }

        return $schema;
    }
}
