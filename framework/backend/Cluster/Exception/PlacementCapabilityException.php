<?php

declare(strict_types=1);

namespace Hilos\Cluster\Exception;

/**
 * Thrown when an agent is placed on a node that does not advertise the capabilities the
 * agent requires. The capability hard-constraint is checked on the leader before any
 * placement frame is sent, so a placement that cannot be satisfied fails loudly at the
 * call site rather than launching an agent on an unfit node. Soft preferences and
 * choosing among several fit nodes are node-selection policy (HIL-182), which extends
 * this same seam.
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
}
