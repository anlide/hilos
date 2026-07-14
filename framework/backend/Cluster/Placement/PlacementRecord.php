<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Constants\AgentConstants;

/**
 * Immutable record of one placed agent: which node hosts it and its last-known state.
 *
 * Held both in the leader-side {@see PlacementRegistry} (the cluster-wide view an
 * elected leader keeps) and in each node's own hosted set (what this node was told to
 * run). It is a value object, replaced wholesale on a state change rather than mutated;
 * {@see agentId()} derives the same "type" / "type:index" key the agent manager uses.
 */
final class PlacementRecord
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $nodeId Id of the node hosting the agent
     * @param PlacementState $state Last-known placement state
     */
    public function __construct(
        public readonly string $agentType,
        public readonly ?string $agentIndex,
        public readonly string $nodeId,
        public readonly PlacementState $state,
    ) {
    }

    /**
     * Returns the agent id ("type" or "type:index") this record keys on.
     *
     * @return string Agent id
     */
    public function agentId(): string
    {
        return $this->agentIndex !== null
            ? $this->agentType . AgentConstants::ID_SEPARATOR . $this->agentIndex
            : $this->agentType;
    }

    /**
     * Returns a copy of this record in a new placement state.
     *
     * @param PlacementState $state New placement state
     * @return self Record in the new state
     */
    public function withState(PlacementState $state): self
    {
        return new self($this->agentType, $this->agentIndex, $this->nodeId, $state);
    }
}
