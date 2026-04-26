<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Row;

use Hilos\BaseDTO;

/**
 * Base backend representation of a logical table row.
 *
 * Table rows are not required to mirror DB entities. A concrete row class owns
 * the payload shape used by a table definition and by table mutation fan-out.
 */
abstract class AbstractTableRow extends BaseDTO
{
    /**
     * Stable row key inside the owning table.
     *
     * @return string|int|null Row key, or null when the row is only a placeholder
     */
    abstract public function getRowKey(): string|int|null;

    /**
     * Routing subjects derived from the row payload.
     *
     * Reserved for future table-aware signal routing. Default: no subjects.
     *
     * @return list<string>
     */
    public function getRoutingSubjects(): array
    {
        return [];
    }
}
