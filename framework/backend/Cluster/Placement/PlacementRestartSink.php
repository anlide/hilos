<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;

/** Master callback for the final placement fact of a POLICY restart. */
interface PlacementRestartSink
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @param string $nodeId New host
     * @throws RtActionsStateCollectionNullException When the cluster registry is unavailable
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    public function onRestartPlaced(string $agentType, ?string $agentIndex, string $nodeId): void;

    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @param string $reason Refusal
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    public function onRestartNotPlaced(string $agentType, ?string $agentIndex, string $reason): void;
}
