<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

/** The lost host and original grace belonging to one leader-local failover request. */
final readonly class PlacementFailover
{
    /**
     * @param string $nodeId Host observed offline
     * @param float $deadline Original absolute monotonic failover due
     * @param ?PlacementRecord $cancellation Exact canceled record, when waiting to settle its stop
     * @param ?PlacementStop $stop Exact stop awaiting the isolation boundary
     */
    public function __construct(
        public string $nodeId,
        public float $deadline,
        public ?PlacementRecord $cancellation = null,
        public ?PlacementStop $stop = null,
    )
    {
    }
}
