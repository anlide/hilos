<?php

declare(strict_types=1);

namespace Hilos\Core\Frontend;

/**
 * Accumulated source facts waiting for one frontend projection flush.
 */
final class SourceChangeSet
{
    /** @var list<SourceChange> */
    private array $changes = [];

    public function add(SourceChange $change): void
    {
        $this->changes[] = $change;
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /**
     * @return list<SourceChange>
     */
    public function all(): array
    {
        return $this->changes;
    }
}
