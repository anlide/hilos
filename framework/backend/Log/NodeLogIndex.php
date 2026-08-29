<?php

declare(strict_types=1);

namespace Hilos\Log;

/**
 * Immutable index of one node's log store at one instant (HIL-753).
 *
 * What {@see LogStoreAgent} holds between walks and hands out: the projections of a
 * {@see LogStoreSnapshot} plus the two things a snapshot cannot know on its own — which node this
 * is, and how much each key grew over the last day. It lives in the agent's memory rather than in
 * runtime state on purpose: the runtime is one truth per cluster, and a node index there would
 * both duplicate the cluster aggregator (HIL-754) and spill full replicas across every node where
 * batched deltas belong (HIL-755).
 *
 * Unavailability is a state and not an exception, the same way {@see LogStoreSnapshot} carries it:
 * {@see $available} false comes with empty projections, which the overview draws as blank tiles
 * rather than as zeros — a zero would claim there were no rotations, and here we simply do not know.
 */
final class NodeLogIndex
{
    /**
     * @param ?string $nodeId Cluster node this index was measured on, or null in a single-node installation
     * @param bool $available Whether the log store could be read
     * @param int $sampledAt Unix timestamp of the walk this index was built from
     * @param list<LogBatchSummary> $batches Rotation batches, ascending by timestamp
     * @param list<LogKeySummary> $keys Log keys across live and archive, ascending by key
     * @param list<LogWorkerSummary> $workers Worker streams, ascending by key, monopolistic ones apart
     * @param array<string, ?int> $growthBytesPerDay Key → bytes written over the last day, null until the window fills
     */
    public function __construct(
        public readonly ?string $nodeId,
        public readonly bool $available,
        public readonly int $sampledAt,
        public readonly array $batches,
        public readonly array $keys,
        public readonly array $workers,
        public readonly array $growthBytesPerDay,
    ) {
    }
}
