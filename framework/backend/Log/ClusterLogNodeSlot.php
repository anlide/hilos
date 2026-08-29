<?php

declare(strict_types=1);

namespace Hilos\Log;

/**
 * One node's place in the cluster-wide log picture (HIL-754).
 *
 * What {@see LogAggregatorAgent} keeps per node: the index exactly as the node that owns those
 * files sent it, plus the moment that frame arrived. The index is stored whole and never merged
 * into what was there before — a node sends its index in full (HIL-755), so replacing the slot is
 * both the cheapest and the only lossless way to hold it.
 *
 * The arrival time is the aggregator's own clock and not the node's: {@see NodeLogIndex::$sampledAt}
 * says when the node measured, this says when we heard, and telling a silent node from a busy one
 * is a question about the second. What counts as silence is HIL-755's to decide; this is only the
 * place to write it down.
 */
final class ClusterLogNodeSlot
{
    /**
     * @param ?string $nodeId Cluster node this slot holds, or null in a single-node installation
     * @param NodeLogIndex $index Index as the node sent it, stored whole
     * @param int $receivedAt Unix timestamp at which the frame carrying that index arrived
     */
    public function __construct(
        public readonly ?string $nodeId,
        public readonly NodeLogIndex $index,
        public readonly int $receivedAt,
    ) {
    }
}
