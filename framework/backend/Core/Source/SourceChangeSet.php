<?php

declare(strict_types=1);

namespace Hilos\Core\Source;

/**
 * Accumulated source facts waiting for one browser flush.
 */
final class SourceChangeSet
{
    /** @var list<SourceChange> */
    private array $changes = [];

    /**
     * Records one source fact for the next browser flush.
     *
     * @param SourceChange $change Source fact to append
     */
    public function add(SourceChange $change): void
    {
        $this->changes[] = $change;
    }

    /**
     * Checks whether there are no recorded source facts.
     *
     * @return bool True when the set has no changes
     */
    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /**
     * Returns the recorded source facts in insertion order.
     *
     * @return list<SourceChange> Source facts waiting for browser flush
     */
    public function all(): array
    {
        return $this->changes;
    }
}
