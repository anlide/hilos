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
 *
 * A record of an agent that runs nowhere because its placement failed — {@see PlacementState::Failed}
 * or {@see PlacementState::Unplaced} — also says why (HIL-447): the human text of the failure and
 * the gate's refusals per node, the latter empty when the failure did not come from the gate. A
 * record of any other state carries neither.
 */
final class PlacementRecord
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $nodeId Id of the node hosting the agent
     * @param PlacementState $state Last-known placement state
     * @param ?string $reason Why the placement failed, or null when it did not or nobody said
     * @param list<PlacementRefusal> $refusals Gate refusals behind the failure; empty for any other failure
     */
    public function __construct(
        public readonly string $agentType,
        public readonly ?string $agentIndex,
        public readonly string $nodeId,
        public readonly PlacementState $state,
        public readonly ?string $reason = null,
        public readonly array $refusals = [],
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
     * The diagnosis survives only into a state that still runs nowhere for want of a placement,
     * {@see PlacementState::Failed} or {@see PlacementState::Unplaced}; any other state drops it,
     * so a record that is placing, started or refused never reads as a failure. A NEW failure is
     * not a state change of this kind: it is a new record built with its own reason.
     *
     * @param PlacementState $state New placement state
     * @return self Record in the new state
     */
    public function withState(PlacementState $state): self
    {
        if ($state !== PlacementState::Failed && $state !== PlacementState::Unplaced) {
            return new self($this->agentType, $this->agentIndex, $this->nodeId, $state);
        }

        return new self($this->agentType, $this->agentIndex, $this->nodeId, $state, $this->reason, $this->refusals);
    }

    /**
     * Whether another record says exactly what this one says: the same agent on the same node in
     * the same state, failed for the same reason with the same refusals.
     *
     * Compared by value, field by field, never by object identity.
     *
     * @param self $other Record to compare with
     * @return bool True when every field is equal
     */
    public function sameAs(self $other): bool
    {
        if ($this->agentType !== $other->agentType
            || $this->agentIndex !== $other->agentIndex
            || $this->nodeId !== $other->nodeId
            || $this->state !== $other->state
            || $this->reason !== $other->reason
            || count($this->refusals) !== count($other->refusals)
        ) {
            return false;
        }

        foreach ($this->refusals as $position => $refusal) {
            if (!$refusal->equals($other->refusals[$position])) {
                return false;
            }
        }

        return true;
    }
}
