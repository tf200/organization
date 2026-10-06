<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * An audit trail of what happens to external collaborators, kept per
 * organization so its admins can read it.
 */
class Version010213Date20261006120000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('organization_external_audit')) {
            $table = $schema->createTable('organization_external_audit');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('organization_id', Types::BIGINT, ['notnull' => false]);
            $table->addColumn('project_id', Types::BIGINT, ['notnull' => false]);
            $table->addColumn('user_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('actor_uid', Types::STRING, ['length' => 64, 'notnull' => false]);
            $table->addColumn('action', Types::STRING, ['length' => 32, 'notnull' => true]);
            $table->addColumn('details', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['organization_id', 'created_at'], 'org_ext_audit_org_idx');
            $table->addIndex(['user_uid', 'action'], 'org_ext_audit_uid_idx');
        }

        return $schema;
    }
}
