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
 * It also names the DIRECTORY it measured ({@see $logDirectory}), which is the one thing about a
 * node's store that no other machine can work out. A page worker holding the cluster picture knows
 * its OWN log root and nobody else's, so the absolute address an operator is told to copy from
 * comes from here rather than from the settings of whoever happens to draw the screen (HIL-483).
 *
 * {@see $takeoutUndoWindowSeconds} rides here for the same reason (HIL-759). The promise that a
 * confirmed batch will not be pruned for a while bottoms out in this node's environment, so with
 * no setting written three nodes of one cluster can honestly hold three different windows, and
 * only the node itself can say which one it holds. Zero is a value and not an absence: it is the
 * installation that wants the pruner to take a confirmed batch on its very next pass.
 *
 * {@see $dueBatchTimestamps} is the one entry here that is a VERDICT and not a measurement
 * (HIL-871): the node is the only judge of its own retention rule, and the screen draws what
 * arrived. It rides on the index rather than on the batch because
 * {@see LogBatchSummary} projects the file walk, which has no settings resolver and is not to
 * grow one, while {@see LogArchiveRetentionPolicy} reads the whole archive at once to decide
 * which of it the newest few protect. An empty list means the rule recommends carrying nothing
 * off, and it is the same answer an index measured by a node that predates this field gives.
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
     * @param ?string $logDirectory Absolute log root of this node, or null when the environment cannot name one
     * @param int $takeoutUndoWindowSeconds Seconds a confirmed batch is protected from the pruner on this node, 0 when it is not to wait
     * @param list<int> $dueBatchTimestamps Batches the retention rule recommends carrying off, ascending; empty when it recommends none
     */
    public function __construct(
        public readonly ?string $nodeId,
        public readonly bool $available,
        public readonly int $sampledAt,
        public readonly array $batches,
        public readonly array $keys,
        public readonly array $workers,
        public readonly array $growthBytesPerDay,
        public readonly ?string $logDirectory = null,
        public readonly int $takeoutUndoWindowSeconds = 0,
        public readonly array $dueBatchTimestamps = [],
    ) {
    }
}
