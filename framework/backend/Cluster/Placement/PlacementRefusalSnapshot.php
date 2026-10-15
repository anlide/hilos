<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Cluster\ClusterCommandConstants;
use Hilos\Constants\AgentConstants;

/**
 * The last failed placement attempt of one agent, as the leader keeps it for inspection (HIL-447).
 *
 * The leader's diagnosis, not a queue: one per agent, replaced by the next failure and dropped
 * by the next accepted attempt, by a start, by a stop, and with the term. It is what lets an
 * operator see why work that never got a placement record is not running — a new agent no node
 * could take has no record to carry the reason. The reason is the human text the attempt failed
 * with; the refusals are the gate's structured answer per node, empty when the failure did not
 * come from the gate.
 */
final class PlacementRefusalSnapshot
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $reason Human-readable text of the failure
     * @param list<PlacementRefusal> $refusals Gate refusals per node; empty for a failure outside the gate
     */
    public function __construct(
        public readonly string $agentType,
        public readonly ?string $agentIndex,
        public readonly string $reason,
        public readonly array $refusals,
    ) {
    }

    /**
     * Returns the agent id ("type" or "type:index") the snapshot is kept under.
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
     * Renders the snapshot as a row of the `test:cluster:inspect` reply.
     *
     * @return array<string, mixed> Snapshot row
     */
    public function toArray(): array
    {
        return [
            ClusterCommandConstants::FIELD_PLACEMENT_AGENT_TYPE => $this->agentType,
            ClusterCommandConstants::FIELD_PLACEMENT_AGENT_INDEX => $this->agentIndex,
            ClusterCommandConstants::FIELD_PLACEMENT_AGENT_ID => $this->agentId(),
            ClusterCommandConstants::FIELD_PLACEMENT_REASON => $this->reason,
            ClusterCommandConstants::FIELD_PLACEMENT_REFUSALS => array_map(
                static fn(PlacementRefusal $refusal): array => $refusal->toArray(),
                $this->refusals,
            ),
        ];
    }
}
