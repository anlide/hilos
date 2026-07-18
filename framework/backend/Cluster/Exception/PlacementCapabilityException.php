<?php

declare(strict_types=1);

namespace Hilos\Cluster\Exception;

/**
 * Thrown when an agent is placed on a node that does not satisfy the agent's hard placement
 * constraints — a missing boolean capability tag ({@see unmetCapabilities()}) or a numeric
 * capacity below a required minimum ({@see unmetResources()}). The hard gate is checked on the
 * leader before any placement frame is sent, so a placement that cannot be satisfied fails
 * loudly at the call site rather than launching an agent on an unfit node. Ranking among the
 * nodes that do clear the gate is the best-fit policy (HIL-182), which never throws.
 */
class PlacementCapabilityException extends ClusterException
{
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
        ));
    }

    /**
     * Builds an exception naming the node, agent, and the capacity minimums it falls short of.
     *
     * @param string $nodeId Target node id
     * @param string $agentId Agent id that could not be placed
     * @param array<string, float> $shortfalls Required minimums keyed by resource name the node does not meet
     * @return self Capability exception
     */
    public static function unmetResources(string $nodeId, string $agentId, array $shortfalls): self
    {
        $demands = [];
        foreach ($shortfalls as $key => $minimum) {
            $demands[] = "{$key}>={$minimum}";
        }

        return new self(sprintf(
            "Cannot place agent '%s' on node '%s': unmet resource minimums [%s]",
            $agentId,
            $nodeId,
            implode(', ', $demands),
        ));
    }
}
