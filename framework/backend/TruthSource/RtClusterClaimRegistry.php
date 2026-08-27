<?php

declare(strict_types=1);

namespace Hilos\TruthSource;

use Hilos\Cluster\Peer\DTO\PeerRtClaimEntry;
use Hilos\Cluster\Peer\DTO\PeerRtClaimRefusedDTO;
use Hilos\Cluster\Placement\PlacementRegistry;

/**
 * Leader-side soft-state map of who in the cluster claims the right to write which RT state.
 *
 * {@see RtNodeSourceMap} answers the same question about one node, which is exactly why this
 * exists: a node can see that IT owns a collection, and cannot see that a second node owns it
 * too. Every node reports its claims to the leader, and only there do the two reports meet.
 *
 * Only an elected leader keeps this view. It is coordination state, never persisted, and is
 * rebuilt from scratch on a leadership change by asking the nodes what they own — the stance
 * {@see PlacementRegistry} takes for placement, and for the same reason: a persisted right
 * would outlive the configuration that produced it and would have to be cleared by hand.
 *
 * Keyed by node id, and a report REPLACES what that node claimed before, because the report is
 * the node's whole ownership rather than a delta. So a right released on the node disappears
 * here on its next report, with nothing to expire and no way for the two to drift apart.
 *
 * What counts as a conflict is the narrow thing, and both axes of the right decide it: two
 * claims collide only when each of them is WHOLE — every operation (HIL-688) over rows that
 * overlap (HIL-589). A co-owner short of an operation and a neighbour holding the rest is the
 * arrangement working, and two agents naming different rows are two owners of different
 * entities. Only the overlap of two whole rights is the defect.
 */
final class RtClusterClaimRegistry
{
    /** @var array<string, list<PeerRtClaimEntry>> Claims of every node that has reported, keyed by node id */
    private array $byNode = [];

    /**
     * Drops everything one node claimed, because it owns nothing or is gone.
     *
     * @param string $nodeId Node to forget
     */
    public function forget(string $nodeId): void
    {
        unset($this->byNode[$nodeId]);
    }

    /**
     * Discards the whole view; used before a fresh leader rebuilds it from node reports.
     */
    public function clear(): void
    {
        $this->byNode = [];
    }

    /**
     * Takes one node's report as its new holding, and names every claim in it that lost.
     *
     * Judging and recording are one step because the answer to the first decides the second: a
     * claim that lost is NOT a holding, and storing it anyway would let the loser go on to
     * dispossess the very node it lost to. That is not hypothetical — the holder reports again
     * every time anything it owns moves, and on that second report it would meet its own
     * challenger sitting in the map as an incumbent.
     *
     * The reported node is judged against what the OTHER nodes hold, never against itself: two
     * owners inside one node are a different defect with a different owner (HIL-685), and the map
     * could not tell them from one agent reported twice anyway.
     *
     * Which side loses is decided by arrival — the leader heard the holder first — and by nothing
     * else. A tie-break on node ids was rejected deliberately: it would move the right to the
     * other node after an election, while the whole promise of the guard is that an owner working
     * correctly is never disturbed.
     *
     * @param string $nodeId Node whose report is being folded in
     * @param list<PeerRtClaimEntry> $claims What that node says its agents own
     * @return list<PeerRtClaimRefusedDTO> One verdict per claim that lost, empty when none did
     */
    public function fold(string $nodeId, array $claims): array
    {
        $refusals = [];
        $held = [];
        foreach ($claims as $claim) {
            $lost = [];
            foreach ($claim->collectionKeys as $collectionKey) {
                $refusal = $this->findConflict($nodeId, $claim, $collectionKey);
                if ($refusal !== null) {
                    $refusals[] = $refusal;
                    $lost[] = $collectionKey;
                }
            }

            $accepted = $lost === [] ? $claim : $claim->without($lost);
            if ($accepted->collectionKeys !== []) {
                $held[] = $accepted;
            }
        }

        if ($held === []) {
            $this->forget($nodeId);
        } else {
            $this->byNode[$nodeId] = $held;
        }

        return $refusals;
    }

    /**
     * Finds the held claim one collection of one report collides with, if any.
     *
     * @param string $nodeId Node whose report is being judged
     * @param PeerRtClaimEntry $claim Claim being judged
     * @param string $collectionKey RT collection of that claim to judge
     * @return ?PeerRtClaimRefusedDTO Verdict against the claim, or null when it stands
     */
    private function findConflict(string $nodeId, PeerRtClaimEntry $claim, string $collectionKey): ?PeerRtClaimRefusedDTO
    {
        foreach ($this->byNode as $holderNodeId => $heldClaims) {
            if ($holderNodeId === $nodeId) {
                continue;
            }

            foreach ($heldClaims as $held) {
                $stateIds = self::overlap($held, $claim, $collectionKey);
                if ($stateIds === null) {
                    continue;
                }

                return new PeerRtClaimRefusedDTO(
                    collectionKey: $collectionKey,
                    stateIds: $stateIds,
                    agentType: $claim->agentType,
                    agentIndex: $claim->agentIndex,
                    agentId: $claim->agentId,
                    holderNodeId: $holderNodeId,
                    holderAgentId: $held->agentId,
                );
            }
        }

        return null;
    }

    /**
     * Which rows two whole rights over one collection both speak for.
     *
     * An empty list is the answer for two collection-wide claims and reads as "all of it", the
     * meaning an unnamed row scope carries everywhere else in this protocol. Where one side or
     * both named rows, the answer is the rows they share, and it is what the refusal and the log
     * line report: naming the whole collection there would accuse two agents that between them
     * write a hundred different entities.
     *
     * @param PeerRtClaimEntry $held Claim the leader already holds
     * @param PeerRtClaimEntry $claim Claim being judged against it
     * @param string $collectionKey RT collection both are over
     * @return ?list<string> Rows both speak for, empty for the whole collection, or null when they do not clash
     */
    private static function overlap(PeerRtClaimEntry $held, PeerRtClaimEntry $claim, string $collectionKey): ?array
    {
        $heldKeys = $held->claimedKeys($collectionKey);
        $claimedKeys = $claim->claimedKeys($collectionKey);
        if ($heldKeys === null && $claimedKeys === null) {
            return $held->ownsFully($collectionKey) && $claim->ownsFully($collectionKey) ? [] : null;
        }

        $shared = [];
        foreach ($claimedKeys ?? $heldKeys ?? [] as $stateId) {
            if ($held->ownsFully($collectionKey, $stateId) && $claim->ownsFully($collectionKey, $stateId)) {
                $shared[] = $stateId;
            }
        }

        return $shared === [] ? null : $shared;
    }
}
