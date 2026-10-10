<?php

declare(strict_types=1);

namespace OCA\Organization\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Capacity is counted per person in projectcreatoraio, so a team no longer
 * carries an FTE figure or a number of projects per FTE.
 */
class Version010215Date20261010000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('organization_teams')) {
            return null;
        }
        $table = $schema->getTable('organization_teams');
        $changed = false;
        foreach (['fte', 'projects_per_fte'] as $column) {
            if ($table->hasColumn($column)) {
                $table->dropColumn($column);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
