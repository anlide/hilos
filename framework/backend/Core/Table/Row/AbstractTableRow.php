<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Row;

use Hilos\BaseDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;

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
     * Row key of a row that has to be addressable — a browser row, a window entry.
     *
     * A placeholder row has no key, and the caller has no honest key to send: an
     * empty one addresses no row and makes every keyless row look like the same
     * row to the window that stores it.
     *
     * @return string|int Stable row key inside the owning table
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function requireRowKey(): string|int
    {
        $rowKey = $this->getRowKey();
        if ($rowKey === null) {
            throw new TableRowKeyMissingException(static::class);
        }

        return $rowKey;
    }

    /**
     * Routing subjects derived from the row payload.
     *
     * Reserved for future table-aware signal routing. Default: no subjects.
     *
     * @return list<string> Routing subject keys derived from the row, empty by default
     */
    public function getRoutingSubjects(): array
    {
        return [];
    }
}
