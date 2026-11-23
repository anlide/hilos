<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

/** One process-local ask incarnation; a retired heap entry cannot expire its replacement. */
final readonly class PlacementAsk
{
    /**
     * @param string $agentId Address whose placement was asked for
     * @param float $deadline Coordinator-clock suppression deadline, monotonic for native on-demand retries
     * @param ?string $leaderNodeId Leader asked, or null when no request could be sent
     * @param ?int $authorityTerm Authority known when the request entered the peer turn
     * @param ?string $requestId Wire identity, absent when the request could not be sent
     */
    public function __construct(
        public string $agentId,
        public float $deadline,
        public ?string $leaderNodeId,
        public ?int $authorityTerm,
        public ?string $requestId,
    ) {
    }
}
