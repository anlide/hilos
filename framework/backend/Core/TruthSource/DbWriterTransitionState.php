<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource;

/**
 * An exact permitted vector, including creators; an empty debt denotes the final state.
 */
final readonly class DbWriterTransitionState
{
    /** @var list<string> Canonical outstanding implementing leaves */
    public array $pendingLeaves;

    /**
     * @param list<string> $pendingLeaves Outstanding implementing leaves
     * @param list<DbWriterClaim> $claims Exact declarations for this state
     * @param list<DbWriterPartition> $partitions Required partition proofs
     */
    public function __construct(
        array $pendingLeaves,
        public array $claims,
        public array $partitions = [],
    ) {
        $pendingLeaves = array_values(array_unique($pendingLeaves));
        sort($pendingLeaves, SORT_STRING);
        $this->pendingLeaves = $pendingLeaves;
    }
}
