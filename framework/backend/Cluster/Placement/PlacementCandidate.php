<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

/**
 * One online node as the placement policy sees it (HIL-448): its declared capability tags and
 * capacities, the share of that capacity its live placements already hold, and how many of them
 * it runs.
 *
 * The coordinator builds a candidate per online node from its own placement registry each time
 * a node is chosen — occupancy is derived, never counted — and both the policy and a placement
 * that names its node judge fit through the one gate here, {@see accepts()}, so the two paths
 * can never disagree about whether a node has room.
 */
final class PlacementCandidate
{
    /**
     * @param string $nodeId Candidate node id
     * @param NodeCapacities $capacities Capability tags and capacities the node declares
     * @param array<string, float> $used Capacity already held by live placements on the node, keyed by resource name
     * @param int $hosted Number of live placements on the node
     * @param bool $isLeader Whether the node currently holds the cluster leadership
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly NodeCapacities $capacities,
        public readonly array $used,
        public readonly int $hosted,
        public readonly bool $isLeader,
    ) {
    }

    /**
     * Returns the node's free capacity of a resource: declared minus held by live placements.
     *
     * @param string $key Resource name
     * @return float Free capacity; negative only when the declaration shrank under running agents
     */
    public function free(string $key): float
    {
        return $this->capacities->capacity($key) - ($this->used[$key] ?? 0.0);
    }

    /**
     * Returns every resource whose free capacity is below the agent's cost.
     *
     * @param ResourceProfile $cost Resource cost of the agent
     * @return array<string, array{cost: float, free: float}> Shortfalls keyed by resource name; empty when all fit
     */
    public function shortfalls(ResourceProfile $cost): array
    {
        $shortfalls = [];
        foreach ($cost->costs as $key => $amount) {
            $free = $this->free($key);
            if ($free < $amount) {
                $shortfalls[$key] = ['cost' => $amount, 'free' => $free];
            }
        }

        return $shortfalls;
    }

    /**
     * The placement gate: the node advertises every required tag, declares a capacity at all,
     * and has free room for every resource the agent costs.
     *
     * @param list<string> $requiredTags Boolean capability tags the agent must have
     * @param ResourceProfile $cost Resource cost of the agent
     * @return bool True when the node may take the agent
     */
    public function accepts(array $requiredTags, ResourceProfile $cost): bool
    {
        foreach ($requiredTags as $tag) {
            if (!$this->capacities->hasTag($tag)) {
                return false;
            }
        }

        return $this->capacities->declaresCapacity() && $this->shortfalls($cost) === [];
    }

    /**
     * Returns the node's load after taking the agent: the highest, over the resources the agent
     * costs, of held-plus-cost divided by declared capacity; 0.0 for an agent that costs nothing.
     *
     * Call it only on a candidate that {@see accepts()} the agent: there every costed resource
     * has a positive declared capacity, so the division is defined.
     *
     * @param ResourceProfile $cost Resource cost of the agent
     * @return float Load after placement, 1.0 meaning the most loaded resource is full
     */
    public function loadAfter(ResourceProfile $cost): float
    {
        $load = 0.0;
        foreach ($cost->costs as $key => $amount) {
            $load = max($load, (($this->used[$key] ?? 0.0) + $amount) / $this->capacities->capacity($key));
        }

        return $load;
    }
}
