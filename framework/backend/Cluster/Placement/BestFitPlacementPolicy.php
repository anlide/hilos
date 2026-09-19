<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

/**
 * The default node-selection policy (HIL-182, HIL-448): places an agent where it leaves the
 * node least loaded, so the fleet fills the nodes in proportion to what they declare.
 *
 * Selection runs in two stages over the candidate nodes:
 *
 * 1. Hard gate — {@see PlacementCandidate::accepts()}: every required capability tag present,
 *    some capacity declared at all, and free room for every resource the agent costs. A node
 *    that exhausted its stock stops being a candidate here, however few agents it runs.
 * 2. Ranking — among the accepted nodes, in order:
 *    1. the lower load after placement ({@see PlacementCandidate::loadAfter()}), equal within
 *       {@see LOAD_EPSILON} so 3/5 and 6/10 tie. Filling every node to the same share is what
 *       makes the spread proportional to capacity, and it sends a heavy agent to the node where
 *       it is the smaller share — the strong one;
 *    2. a node that is not the leader ahead of the leader (HIL-445, rule 3). The leader is last
 *       among equals right after the load, before the head count: behind the head count it
 *       would take every N-th free agent as soon as the others caught up, and the rule would
 *       stop biting. It stays a candidate — when it is the only node that fits, it gets the work;
 *    3. fewer live placements — the head count (12cb4386). An agent that costs nothing scores 0
 *       everywhere and is spread by this rule alone, exactly as before costs existed;
 *    4. the greater total declared capacity — the stronger node;
 *    5. the lexicographically smaller id, so the pick is deterministic.
 */
final class BestFitPlacementPolicy implements PlacementPolicy
{
    /** @var float Tolerance under which two loads after placement count as equal */
    private const float LOAD_EPSILON = 1e-9;

    /**
     * @param list<string> $requiredTags Boolean capability tags the agent must have
     * @param ResourceProfile $cost Resource cost of the agent
     * @param array<string, PlacementCandidate> $candidates Online candidate nodes keyed by node id
     * @return ?string Chosen node id, or null when no candidate clears the gate
     */
    public function selectNode(array $requiredTags, ResourceProfile $cost, array $candidates): ?string
    {
        $chosen = null;
        $chosenLoad = 0.0;

        $nodeIds = array_keys($candidates);
        sort($nodeIds);
        foreach ($nodeIds as $nodeId) {
            $candidate = $candidates[$nodeId];
            if (!$candidate->accepts($requiredTags, $cost)) {
                continue;
            }

            $load = $candidate->loadAfter($cost);
            if ($chosen === null || $this->ranksAbove($candidate, $load, $chosen, $chosenLoad)) {
                $chosen = $candidate;
                $chosenLoad = $load;
            }
        }

        return $chosen?->nodeId;
    }

    /**
     * Reports whether a candidate ranks strictly above the one chosen so far. The candidates are
     * visited in id order, so an exact tie keeps the earlier — smaller — id.
     *
     * @param PlacementCandidate $candidate Candidate under consideration
     * @param float $load Its load after placement
     * @param PlacementCandidate $chosen Best candidate so far
     * @param float $chosenLoad Its load after placement
     * @return bool True when the candidate should replace the chosen one
     */
    private function ranksAbove(PlacementCandidate $candidate, float $load, PlacementCandidate $chosen, float $chosenLoad): bool
    {
        if (abs($load - $chosenLoad) > self::LOAD_EPSILON) {
            return $load < $chosenLoad;
        }

        if ($candidate->isLeader !== $chosen->isLeader) {
            return !$candidate->isLeader;
        }

        if ($candidate->hosted !== $chosen->hosted) {
            return $candidate->hosted < $chosen->hosted;
        }

        return $candidate->capacities->totalCapacity() > $chosen->capacities->totalCapacity();
    }
}
