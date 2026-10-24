<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Immutable membership of one journal read, preserving its order and integer keys.
 *
 * @template T of object
 * @implements IteratorAggregate<int, T>
 */
final readonly class ChangeLogReadCollection implements Countable, IteratorAggregate
{
    /**
     * @param array<int, T> $items Read values in their source order
     */
    private function __construct(private array $items)
    {
    }

    /**
     * @template TItem of object
     * @param array<int, TItem> $items Dense list or map keyed by journal id
     * @return self<TItem> Snapshot with the supplied keys and order
     */
    public static function fromArray(array $items): self
    {
        return new self($items);
    }

    /**
     * @param int $key Original integer key
     * @return T|null Value at the key, or null when absent
     */
    public function get(int $key): ?object
    {
        return $this->items[$key] ?? null;
    }

    /** @return T|null First value in source order, or null when empty */
    public function first(): ?object
    {
        return $this->items[array_key_first($this->items)] ?? null;
    }

    /** @return T|null Last value in source order, or null when empty */
    public function last(): ?object
    {
        return $this->items[array_key_last($this->items)] ?? null;
    }

    /** @return bool Whether the snapshot contains no values */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return int Number of values in this snapshot */
    public function count(): int
    {
        return count($this->items);
    }

    /** @return Traversable<int, T> Independent walk over the original keys and order */
    public function getIterator(): Traversable
    {
        yield from $this->items;
    }

    /** @return array<int, T> Explicit array conversion preserving keys and order */
    public function toArray(): array
    {
        return $this->items;
    }
}
