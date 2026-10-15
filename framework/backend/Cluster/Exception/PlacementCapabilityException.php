<?php

declare(strict_types=1);

namespace Hilos\Cluster\Exception;

use Hilos\Cluster\Placement\PlacementRefusal;

/**
 * Thrown when an agent is placed on a node that does not satisfy the agent's hard placement
 * gate, which refuses for one of three reasons: a missing boolean capability tag
 * ({@see unmetCapabilities()}), a node that declares no capacity and so accepts no placed work
 * ({@see noDeclaredCapacity()}, HIL-445), or too little free capacity for the agent's cost
 * ({@see insufficientCapacity()}, HIL-448) — the mandatory slot every placed agent costs among
 * them (HIL-447). The gate is checked on the leader before any placement frame is sent, so a
 * placement that cannot be satisfied fails loudly at the call site rather than launching an agent
 * on an unfit node, and once more on the node before it starts the agent. Ranking among the nodes that do clear the gate
 * is the best-fit policy (HIL-182), which never throws.
 *
 * The message is for a person; {@see $refusal} is the same refusal for a machine (HIL-447), built
 * by each factory from the data it was handed — never parsed back out of the message.
 */
class PlacementCapabilityException extends ClusterException
{
    /**
     * @param string $message Human-readable refusal text
     * @param PlacementRefusal $refusal The same refusal, structured: node, code and details
     */
    public function __construct(string $message, public readonly PlacementRefusal $refusal)
    {
        parent::__construct($message);
    }

    /**
     * Builds an exception naming the node, agent, and the capabilities it lacks.
     *
     * @param string $nodeId Target node id
     * @param string $agentId Agent id that could not be placed
     * @param list<string> $missing Required capability tags the node does not advertise
     * @return self Capability exception
     */
    public static function unmetCapabilities(string $nodeId, string $agentId, array $missing): self
    {
        return new self(sprintf(
            "Cannot place agent '%s' on node '%s': missing required capabilities [%s]",
            $agentId,
            $nodeId,
            implode(', ', $missing),
        ), PlacementRefusal::unmetCapabilities($nodeId, $missing));
    }

    /**
     * Builds an exception for a node that declares no capacity at all, and therefore takes no
     * placed work whatever the agent costs.
     *
     * @param string $nodeId Target node id
     * @param string $agentId Agent id that could not be placed
     * @return self Capability exception
     */
    public static function noDeclaredCapacity(string $nodeId, string $agentId): self
    {
        return new self(sprintf(
            "Cannot place agent '%s' on node '%s': the node declares no capacity, so it accepts no placed work",
            $agentId,
            $nodeId,
        ), PlacementRefusal::noDeclaredCapacity($nodeId));
    }

    /**
     * Builds an exception naming the node, agent, and every resource whose free capacity is below
     * the agent's cost.
     *
     * @param string $nodeId Target node id
     * @param string $agentId Agent id that could not be placed
     * @param array<string, array{cost: float, free: float}> $shortfalls Cost and free capacity keyed by resource name
     * @return self Capability exception
     */
    public static function insufficientCapacity(string $nodeId, string $agentId, array $shortfalls): self
    {
        $demands = [];
        foreach ($shortfalls as $key => $shortfall) {
            $demands[] = "{$key}: needs {$shortfall[PlacementRefusal::FIELD_COST]}, free {$shortfall[PlacementRefusal::FIELD_FREE]}";
        }

        return new self(sprintf(
            "Cannot place agent '%s' on node '%s': insufficient free capacity [%s]",
            $agentId,
            $nodeId,
            implode(', ', $demands),
        ), PlacementRefusal::insufficientCapacity($nodeId, $shortfalls));
    }
}
