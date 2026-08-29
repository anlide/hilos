<?php

declare(strict_types=1);

namespace Hilos\Log;

/**
 * Immutable difference between two consecutive {@see NodeLogIndex} states (HIL-753).
 *
 * What changed since the previous walk — a key appeared, grew, vanished, a batch arrived or was
 * cleaned away — derived by comparing the two indexes rather than journaled as it happens. No
 * history is kept: the consumer this exists for (HIL-755, deltas to the cluster aggregator) asked
 * for the difference to the last state it was told about, not for a log of changes.
 */
final class NodeLogIndexDelta
{
    /**
     * @param list<string> $appearedKeys Keys present now and absent from the previous index
     * @param list<string> $vanishedKeys Keys present in the previous index and absent now
     * @param array<string, int> $grownKeys Key → bytes added since the previous index (growth only)
     * @param list<int> $appearedBatchTimestamps Rotation batches present now and absent before
     * @param list<int> $vanishedBatchTimestamps Rotation batches present before and absent now
     * @param bool $availabilityChanged Whether the store crossed between readable and unreadable
     */
    public function __construct(
        public readonly array $appearedKeys,
        public readonly array $vanishedKeys,
        public readonly array $grownKeys,
        public readonly array $appearedBatchTimestamps,
        public readonly array $vanishedBatchTimestamps,
        public readonly bool $availabilityChanged,
    ) {
    }
}
