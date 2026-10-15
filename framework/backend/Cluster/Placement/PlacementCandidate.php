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
 * that names its node judge fit through the one gate here, {@see refusal()}, so the two paths
 * can never disagree about whether a node has room. {@see accepts()} is the same gate asked for
 * a yes or a no.
 *
 * Every cost handed in is read as placement charges it, {@see ResourceProfile::forPlacement()}
 * (HIL-447): the agent's project cost plus its one mandatory slot. The candidate normalizes it
 * itself, so a caller that hands in {@see ResourceProfile::none()} directly still meets the slot
 * rather than walking past it, and a cost normalized already is not charged twice.
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
     * Returns every resource whose free capacity is below the agent's effective cost, its slot
     * included: a node that declares no `slots` is short of one for every agent.
     *
     * @param ResourceProfile $cost Resource cost of the agent, normalized here when it is not yet
     * @return array<string, array{cost: float, free: float}> Shortfalls keyed by resource name; empty when all fit
     */
    public function shortfalls(ResourceProfile $cost): array
    {
        $shortfalls = [];
        foreach ($cost->forPlacement()->costs as $key => $amount) {
            $free = $this->free($key);
            if ($free < $amount) {
                $shortfalls[$key] = [PlacementRefusal::FIELD_COST => $amount, PlacementRefusal::FIELD_FREE => $free];
            }
        }

        return $shortfalls;
    }

    /**
     * The placement gate, answering why the node may not take the agent (HIL-447): the first gate
     * it fails, checked in order — every required tag advertised, a capacity declared at all, then
     * free room for every resource the agent costs, its mandatory slot among them. A node that
     * declares some capacity but no `slots` is therefore short of a slot, not undeclared.
     *
     * @param list<string> $requiredTags Boolean capability tags the agent must have
     * @param ResourceProfile $cost Resource cost of the agent, normalized by {@see shortfalls()}
     * @return ?PlacementRefusal The first gate the node fails, or null when it may take the agent
     */
    public function refusal(array $requiredTags, ResourceProfile $cost): ?PlacementRefusal
    {
        $missing = array_values(array_filter(
            $requiredTags,
            fn(string $tag): bool => !$this->capacities->hasTag($tag),
        ));
        if ($missing !== []) {
            return PlacementRefusal::unmetCapabilities($this->nodeId, $missing);
        }

        if (!$this->capacities->declaresCapacity()) {
            return PlacementRefusal::noDeclaredCapacity($this->nodeId);
        }

        $shortfalls = $this->shortfalls($cost);

        return $shortfalls === [] ? null : PlacementRefusal::insufficientCapacity($this->nodeId, $shortfalls);
    }

    /**
     * The placement gate as a yes or a no: {@see refusal()} found nothing to refuse.
     *
     * @param list<string> $requiredTags Boolean capability tags the agent must have
     * @param ResourceProfile $cost Resource cost of the agent, normalized by {@see shortfalls()}
     * @return bool True when the node may take the agent
     */
    public function accepts(array $requiredTags, ResourceProfile $cost): bool
    {
        return $this->refusal($requiredTags, $cost) === null;
    }

    /**
     * Returns the node's load after taking the agent: the highest, over the resources the agent
     * costs, of held-plus-cost divided by declared capacity. The slot is one of them, so an agent
     * with no project cost loads the node by its share of the slots, never by a flat zero.
     *
     * Call it only on a candidate that {@see accepts()} the agent: there every costed resource
     * has a positive declared capacity, so the division is defined.
     *
     * @param ResourceProfile $cost Resource cost of the agent, normalized here when it is not yet
     * @return float Load after placement, 1.0 meaning the most loaded resource is full
     */
    public function loadAfter(ResourceProfile $cost): float
    {
        $load = 0.0;
        foreach ($cost->forPlacement()->costs as $key => $amount) {
            $load = max($load, (($this->used[$key] ?? 0.0) + $amount) / $this->capacities->capacity($key));
        }

        return $load;
    }
}
