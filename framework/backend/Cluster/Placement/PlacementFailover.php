<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

/** The lost host and original grace belonging to one leader-local failover request. */
final readonly class PlacementFailover
{
    /**
     * @param string $nodeId Host observed offline
     * @param float $deadline Original absolute monotonic failover due
     */
    public function __construct(public string $nodeId, public float $deadline)
    {
    }
}
