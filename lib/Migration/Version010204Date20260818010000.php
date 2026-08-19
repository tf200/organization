<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010204Date20260818010000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('org_contract_versions')) {
            $table = $schema->createTable('org_contract_versions');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('contract_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('version_number', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('original_filename', Types::STRING, ['length' => 255, 'notnull' => true]);
            $table->addColumn('storage_key', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('mime_type', Types::STRING, ['length' => 127, 'notnull' => true]);
            $table->addColumn('file_size', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('checksum', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('uploaded_by_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['contract_id', 'version_number'], 'org_contract_version_uniq');
            $table->addUniqueIndex(['storage_key'], 'org_contract_ver_store_uniq');
        }

        if (!$schema->hasTable('org_contract_sign_reqs')) {
            $table = $schema->createTable('org_contract_sign_reqs');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('organization_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('contract_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('contract_version_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('libresign_file_uuid', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('status', Types::STRING, ['length' => 32, 'notnull' => true]);
            $table->addColumn('signers_json', Types::TEXT, ['notnull' => true]);
            $table->addColumn('source_checksum', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('created_by_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('sent_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('completed_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('signed_storage_key', Types::STRING, ['length' => 64, 'notnull' => false]);
            $table->addColumn('signed_checksum', Types::STRING, ['length' => 64, 'notnull' => false]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['organization_id', 'contract_id'], 'org_contract_sign_lookup');
            $table->addUniqueIndex(['libresign_file_uuid'], 'org_contract_sign_uuid');
        }

        return $schema;
    }
}
