<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

/** Master callback for the final placement fact of a POLICY restart. */
interface PlacementRestartSink
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @param string $nodeId New host
     */
    public function onRestartPlaced(string $agentType, ?string $agentIndex, string $nodeId): void;

    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @param string $reason Refusal
     */
    public function onRestartNotPlaced(string $agentType, ?string $agentIndex, string $reason): void;
}
