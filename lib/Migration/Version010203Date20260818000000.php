<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010203Date20260818000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('organization_contracts')) {
            $table = $schema->createTable('organization_contracts');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('organization_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('display_name', Types::STRING, ['length' => 255, 'notnull' => true]);
            $table->addColumn('original_filename', Types::STRING, ['length' => 255, 'notnull' => true]);
            $table->addColumn('storage_key', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('mime_type', Types::STRING, ['length' => 127, 'notnull' => true]);
            $table->addColumn('file_size', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('checksum', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('uploaded_by_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['organization_id', 'created_at'], 'org_contract_org_created');
            $table->addUniqueIndex(['storage_key'], 'org_contract_storage_uniq');
        }

        return $schema;
    }
}
