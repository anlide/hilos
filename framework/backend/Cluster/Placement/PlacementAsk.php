<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

/** One process-local ask incarnation; a retired heap entry cannot expire its replacement. */
final readonly class PlacementAsk
{
    /**
     * @param string $agentId Address whose placement was asked for
     * @param float $deadline Coordinator-clock suppression deadline, monotonic for native on-demand retries
     */
    public function __construct(public string $agentId, public float $deadline)
    {
    }
}
