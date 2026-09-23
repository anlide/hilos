<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Peer\DTO\PeerPlacementVerdictDTO;
use Hilos\Core\Exception\InvalidArgumentException;

/**
 * Local port the placement coordinator uses to tell the master an agent was not placed.
 *
 * The negative half of a {@see PeerPlacementVerdictDTO}: a placed verdict names a node
 * and the parked frame leaves by address, so the master does not need to hear it. A
 * not-placed verdict has nowhere to send the frame, and the master answers whoever is
 * waiting. The MASTER implements it. A test supplies a fake so the coordinator runs
 * without a daemon loop.
 */
interface PlacementVerdictSink
{
    /**
     * Answers every frame held for an agent the leader could not place.
     *
     * @param string $agentType Agent type that was not placed
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $reason Why the agent was not placed, including the record state
     * @throws InvalidArgumentException When an answer the listener owes a waiting frame cannot be named
     */
    public function onAgentNotPlaced(string $agentType, ?string $agentIndex, string $reason): void;
}
