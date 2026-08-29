<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Runtime\State\Item\HilosClusterNode;

/**
 * One node of the cluster log picture, with the cluster's own word on whether it is still there
 * (HIL-755).
 *
 * What {@see LogAggregatorAgent::nodeViews()} hands out: the slot exactly as the node reported it,
 * plus one fact the slot cannot hold. Membership is nobody's measurement of the logs - it is the
 * master's register, published as {@see HilosClusterNode} - so it is read at the moment the picture
 * is handed over and joined here, rather than written into the slot or into
 * {@see ClusterLogTotals}. That keeps the summing a pure function of the frames, which is what lets
 * a frame be filed without asking anything about the cluster.
 *
 * It is read at handover and not on arrival because of when a node dies: it falls over AFTER
 * sending its last frame, so a liveness taken as the frame landed would say "alive" every time.
 *
 * {@see $online} false does not take the node's figures out of the sums. The files on the disk of a
 * machine that fell over still exist, and the count of such nodes is named beside the total instead.
 */
final class ClusterLogNodeView
{
    /**
     * @param ?string $nodeId Cluster node this view describes, or null in a single-node installation
     * @param ClusterLogNodeSlot $slot Slot holding the index as that node last reported it
     * @param bool $online Whether the cluster still counts the node as connected
     */
    public function __construct(
        public readonly ?string $nodeId,
        public readonly ClusterLogNodeSlot $slot,
        public readonly bool $online,
    ) {
    }
}
