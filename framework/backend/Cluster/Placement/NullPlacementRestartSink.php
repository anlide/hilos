<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

/** Inert restart sink for a placement coordinator without a registered master. */
final class NullPlacementRestartSink implements PlacementRestartSink
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @param string $nodeId New host
     */
    public function onRestartPlaced(string $agentType, ?string $agentIndex, string $nodeId): void
    {
    }

    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @param string $reason Refusal reason
     */
    public function onRestartNotPlaced(string $agentType, ?string $agentIndex, string $reason): void
    {
    }
}
