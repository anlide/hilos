<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Actions;

use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Base class for single-item actions (e.g. update, delete).
 *
 * Subclasses implement domain-specific item actions.
 * Return a TableRowMutationDTO from each mutating method so the caller can broadcast it.
 */
abstract class TableItemActions
{
    /**
     * Creates item actions for a single table row.
     *
     * @param TableDefinition $definition Table definition this actions instance belongs to
     * @param string|int $rowKey Key of the row these actions operate on
     */
    public function __construct(
        protected readonly TableDefinition $definition,
        protected readonly string|int $rowKey,
    ) {
    }

    /**
     * Creates a row mutation DTO for broadcasting.
     *
     * @param TableMutationType $type Mutation type (e.g. update, delete)
     * @param ?AbstractTableRow $row Optional row data for create/update mutations
     *
     * @return TableRowMutationDTO Created row mutation DTO for broadcasting
     */
    protected function mutation(TableMutationType $type, ?AbstractTableRow $row = null): TableRowMutationDTO
    {
        return new TableRowMutationDTO($type, $this->rowKey, $row);
    }
}
