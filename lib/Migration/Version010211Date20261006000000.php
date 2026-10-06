<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * External collaborator lifecycle: a storage quota per plan for externals, and
 * when a grant's private folder was handed to the project owner.
 */
class Version010211Date20261006000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('plans')) {
            $table = $schema->getTable('plans');
            if (!$table->hasColumn('external_storage_quota')) {
                $table->addColumn('external_storage_quota', Types::BIGINT, ['notnull' => false]);
            }
        }

        if ($schema->hasTable('organization_project_externals')) {
            $table = $schema->getTable('organization_project_externals');
            if (!$table->hasColumn('folder_released_at')) {
                $table->addColumn('folder_released_at', Types::DATETIME, ['notnull' => false]);
            }
        }

        return $schema;
    }
}
