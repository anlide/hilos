<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Actions;

use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Base class for table-level actions (e.g. create).
 *
 * Subclasses implement domain-specific collection actions.
 * Return a TableRowMutationDTO from each mutating method so the caller can broadcast it.
 */
abstract class TableActions
{
    /**
     * Creates table-level actions instance.
     *
     * @param TableDefinition $definition Table definition this actions instance belongs to
     */
    public function __construct(
        protected readonly TableDefinition $definition,
    ) {
    }

    /**
     * Creates a row mutation DTO for broadcasting.
     *
     * @param TableMutationType $type Mutation type (e.g. insert, update, delete)
     * @param string|int $rowKey Row key affected
     * @param ?AbstractTableRow $row Optional row data for create/update mutations
     *
     * @return TableRowMutationDTO Created row mutation DTO for broadcasting
     */
    protected function mutation(TableMutationType $type, string|int $rowKey, ?AbstractTableRow $row = null): TableRowMutationDTO
    {
        return new TableRowMutationDTO($type, $rowKey, $row);
    }
}
