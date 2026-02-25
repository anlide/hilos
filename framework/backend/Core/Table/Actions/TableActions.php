<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Actions;

use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;

/**
 * Base class for table-level actions (e.g. create).
 *
 * Subclasses implement domain-specific collection actions.
 * Return a TableMutationEntry from each mutating method so the caller can broadcast it.
 */
abstract class TableActions
{
    public function __construct(
        protected readonly TableDefinition $definition,
    ) {
    }

    /**
     * Helper: create a mutation entry for broadcasting.
     */
    protected function mutation(TableMutationType $type, string|int $rowId, ?array $row = null): TableMutationEntry
    {
        return new TableMutationEntry($type, $rowId, $row);
    }
}
