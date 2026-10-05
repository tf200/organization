<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * External collaborators: people who work on single projects without being
 * organization members. They never get an organization_members row.
 */
class Version010210Date20261005000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('organization_externals')) {
            $table = $schema->createTable('organization_externals');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('user_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('email', Types::STRING, ['length' => 255, 'notnull' => true]);
            $table->addColumn('display_name', Types::STRING, ['length' => 255, 'notnull' => true]);
            $table->addColumn('company', Types::STRING, ['length' => 255, 'notnull' => false]);
            $table->addColumn('phone', Types::STRING, ['length' => 64, 'notnull' => false]);
            $table->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'invited']);
            $table->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('activated_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('last_seen_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('disabled_at', Types::DATETIME, ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_uid'], 'org_externals_uid_uidx');
            $table->addUniqueIndex(['email'], 'org_externals_email_uidx');
        }

        if (!$schema->hasTable('organization_project_externals')) {
            $table = $schema->createTable('organization_project_externals');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('organization_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('project_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('user_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'pending']);
            $table->addColumn('functional_role_keys', Types::TEXT, ['notnull' => false]);
            $table->addColumn('drasci_roles', Types::TEXT, ['notnull' => false]);
            $table->addColumn('invited_by', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('revoked_by', Types::STRING, ['length' => 64, 'notnull' => false]);
            $table->addColumn('invited_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('accepted_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('expires_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('revoked_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('warned_at', Types::DATETIME, ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['project_id', 'user_uid'], 'org_proj_ext_project_uid_uidx');
            $table->addIndex(['organization_id', 'status'], 'org_proj_ext_org_status_idx');
            $table->addIndex(['status', 'expires_at'], 'org_proj_ext_status_exp_idx');
            $table->addIndex(['user_uid', 'status'], 'org_proj_ext_uid_status_idx');
        }

        if (!$schema->hasTable('organization_external_invites')) {
            $table = $schema->createTable('organization_external_invites');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('user_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('grant_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('token_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('expires_at', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('used_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('last_sent_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('send_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['token_hash'], 'org_ext_invites_token_uidx');
            $table->addIndex(['user_uid'], 'org_ext_invites_uid_idx');
        }

        return $schema;
    }
}
