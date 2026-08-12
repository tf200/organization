<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010015Date20260812000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('org_storage_thresholds')) {
            $table = $schema->createTable('org_storage_thresholds');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('resource_type', Types::STRING, [
                'length' => 16,
                'notnull' => true,
            ]);
            $table->addColumn('organization_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('resource_id', Types::STRING, [
                'length' => 64,
                'notnull' => true,
            ]);
            $table->addColumn('threshold', Types::SMALLINT, [
                'default' => 0,
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['organization_id', 'resource_type', 'resource_id'], 'org_storage_resource_uniq');
        }

        return $schema;
    }
}
