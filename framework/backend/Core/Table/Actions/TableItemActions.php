<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Actions;

use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;

/**
 * Base class for single-item actions (e.g. update, delete).
 *
 * Subclasses implement domain-specific item actions.
 * Return a TableMutationEntry from each mutating method so the caller can broadcast it.
 */
abstract class TableItemActions
{
    public function __construct(
        protected readonly TableDefinition $definition,
        protected readonly string|int $itemId,
    ) {
    }

    /**
     * Helper: create a mutation entry for broadcasting.
     */
    protected function mutation(TableMutationType $type, ?array $row = null): TableMutationEntry
    {
        return new TableMutationEntry($type, $this->itemId, $row);
    }
}
