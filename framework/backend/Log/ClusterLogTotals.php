<?php

declare(strict_types=1);

namespace Hilos\Log;

/**
 * Immutable cluster-wide summary of the log stores, the overview tiles in one object (HIL-754).
 *
 * Projected by {@see ClusterLogIndex::totals()} from the slots the aggregator holds, so it says
 * what every node together has: when anything last rotated anywhere, how many batches exist, how
 * many streams of each class there are and what they weigh, and how much the cluster wrote in the
 * last day.
 *
 * What is NOT known is named by a number rather than swallowed. A node whose directory could not be
 * read sends an index with {@see NodeLogIndex::$available} false and empty projections, so it adds
 * nothing to the sums — {@see $unavailableNodeCount} is what stops that from reading as "nothing
 * there". The same for the day figure: a key whose window has not filled yet counts into
 * {@see $keysWithoutGrowthWindow} instead of contributing a zero, because zero would claim nothing
 * was written where the truth is that we have not measured long enough.
 *
 * The class breakdowns are maps and not three named pairs of fields on purpose: the classes are
 * {@see LogKeySummary}'s to name, and there are already four of them.
 */
final class ClusterLogTotals
{
    /**
     * @param int $nodeCount Nodes that have reported an index at least once
     * @param int $unavailableNodeCount Of those, how many last reported an unreadable log store
     * @param ?int $lastRotationAt Newest rotation batch anywhere, or null when no node has any batch
     * @param int $batchCount Rotation batches summed over the cluster
     * @param array<string, int> $streamCountByClass Stream class → how many (key, node) pairs it has
     * @param array<string, int> $bytesByClass Stream class → summed weight in bytes
     * @param ?int $growthBytesPerDay Bytes written cluster-wide over the last day, or null while no window has filled
     * @param int $keysWithoutGrowthWindow (key, node) pairs whose day window is not a day old yet
     */
    public function __construct(
        public readonly int $nodeCount,
        public readonly int $unavailableNodeCount,
        public readonly ?int $lastRotationAt,
        public readonly int $batchCount,
        public readonly array $streamCountByClass,
        public readonly array $bytesByClass,
        public readonly ?int $growthBytesPerDay,
        public readonly int $keysWithoutGrowthWindow,
    ) {
    }
}
