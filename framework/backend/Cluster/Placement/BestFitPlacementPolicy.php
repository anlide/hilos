<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

/**
 * The default node-selection policy (HIL-182): places an agent on the capable node that best
 * fits its resource demand — heavy workers gravitate to strong nodes, light ones to weak.
 *
 * Selection runs in two stages over the candidate nodes:
 *
 * 1. Hard gate — a node is eligible only when it advertises every required capability tag and
 *    its declared capacity meets every hard minimum in the profile. This mirrors the
 *    coordinator's own capability check, so the policy never picks a node a placement would
 *    then reject.
 * 2. Soft ranking — among eligible nodes, each is scored by the profile's preference weights
 *    against the node's declared capacities (a heavier preference for a resource pulls toward
 *    nodes that have more of it). The highest score wins; ties break toward the node already
 *    hosting the fewest placed agents, then toward the node with the greater total declared
 *    capacity (the stronger node), then toward the lexicographically smaller id so the pick is
 *    deterministic.
 *
 * An empty profile scores every eligible node zero, so selection falls through to the
 * tiebreaks: the least loaded capable node, and the strongest among equals. That makes
 * "spread over the capable nodes" the sensible default before any agent declares a numeric
 * demand — without it a fleet of identical agents would pile onto one node, since declared
 * capacity alone never changes as agents land.
 */
final class BestFitPlacementPolicy implements PlacementPolicy
{
    /**
     * @param list<string> $requiredTags Boolean capability tags the agent must have
     * @param ResourceProfile $profile Numeric hard minimums and soft preferences of the agent
     * @param array<string, NodeCapacities> $candidates Candidate nodes' capacities keyed by node id
     * @param array<string, int> $hosted Agents each candidate already hosts, keyed by node id
     * @return ?string Chosen node id, or null when no candidate satisfies the hard gate
     */
    public function selectNode(
        array $requiredTags,
        ResourceProfile $profile,
        array $candidates,
        array $hosted = [],
    ): ?string {
        $chosen = null;
        $chosenScore = 0.0;
        $chosenLoad = 0;
        $chosenTotal = 0.0;

        $nodeIds = array_keys($candidates);
        sort($nodeIds);
        foreach ($nodeIds as $nodeId) {
            $capacities = $candidates[$nodeId];
            if (!$this->isEligible($requiredTags, $profile, $capacities)) {
                continue;
            }

            $score = $this->score($profile, $capacities);
            $load = $hosted[$nodeId] ?? 0;
            $total = $capacities->totalCapacity();
            if ($chosen === null
                || $score > $chosenScore
                || ($score === $chosenScore && $load < $chosenLoad)
                || ($score === $chosenScore && $load === $chosenLoad && $total > $chosenTotal)) {
                $chosen = $nodeId;
                $chosenScore = $score;
                $chosenLoad = $load;
                $chosenTotal = $total;
            }
        }

        return $chosen;
    }

    /**
     * Reports whether a node clears the hard gate: every required tag present and every hard
     * minimum met.
     *
     * @param list<string> $requiredTags Required capability tags
     * @param ResourceProfile $profile Agent resource profile
     * @param NodeCapacities $capacities Candidate node capacities
     * @return bool True when the node is eligible to host the agent
     */
    private function isEligible(array $requiredTags, ResourceProfile $profile, NodeCapacities $capacities): bool
    {
        foreach ($requiredTags as $tag) {
            if (!$capacities->hasTag($tag)) {
                return false;
            }
        }

        foreach ($profile->minimums as $key => $minimum) {
            if ($capacities->capacity($key) < $minimum) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scores an eligible node by the profile's preference weights against its capacities.
     *
     * @param ResourceProfile $profile Agent resource profile
     * @param NodeCapacities $capacities Candidate node capacities
     * @return float Weighted preference score; 0.0 when the profile has no preferences
     */
    private function score(ResourceProfile $profile, NodeCapacities $capacities): float
    {
        $score = 0.0;
        foreach ($profile->preferences as $key => $weight) {
            $score += $weight * $capacities->capacity($key);
        }

        return $score;
    }
}
