<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Closure;

/** Exact lifetime of a negative decision and the local effects it admitted. */
final readonly class PlacementVerdictBasis
{
    /**
     * @param Closure(self): bool $current Whether the original decision still applies
     * @param Closure(self): void $retire Releases this decision from the coordinator's live index
     */
    public function __construct(private Closure $current, private Closure $retire)
    {
    }

    public function __destruct()
    {
        ($this->retire)($this);
    }

    /** @return bool Whether the original decision can still affect its held signals */
    public function isCurrent(): bool
    {
        return ($this->current)($this);
    }
}
