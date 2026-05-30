<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Collection;

use Countable;
use Hilos\Core\Table\DTO\TableMutationSignalData;
use IteratorAggregate;
use Traversable;

/**
 * Typed list of table mutation WebSocket payloads.
 *
 * @implements IteratorAggregate<int, TableMutationSignalData>
 */
final class TableMutationSignalCollection implements Countable, IteratorAggregate
{
    /** @var list<TableMutationSignalData> */
    private array $signals = [];

    /**
     * Appends one table mutation payload to the collection.
     *
     * @param TableMutationSignalData $signal Table mutation payload ready for WebSocket serialization
     */
    public function add(TableMutationSignalData $signal): void
    {
        $this->signals[] = $signal;
    }

    /**
     * @return int Number of mutation payloads in the collection
     */
    public function count(): int
    {
        return count($this->signals);
    }

    /**
     * Iterates table mutation payloads in insertion order.
     *
     * @return Traversable<int, TableMutationSignalData> Mutation payloads in insertion order
     */
    public function getIterator(): Traversable
    {
        yield from $this->signals;
    }
}
