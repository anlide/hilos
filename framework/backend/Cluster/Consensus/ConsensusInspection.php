<?php

declare(strict_types=1);

namespace Hilos\Cluster\Consensus;

/**
 * Read-only window into a consensus coordinator's term and role.
 *
 * The {@see \Hilos\Cluster\Leadership} seam exposes only the caller-facing verdicts
 * (leader / quorum); this narrow interface adds the two internal consensus values a
 * test harness asserts on — the monotonic election term and the current
 * {@see \Hilos\Cluster\Consensus\ConsensusRole}. Only a
 * {@see \Hilos\Cluster\Consensus\ClusterCoordinator} carries them, so
 * {@see \Hilos\Cluster\ClusterContext::inspect()} reports them as null on a node
 * whose leadership seam is an inert {@see \Hilos\Cluster\PendingLeadership} or
 * {@see \Hilos\Cluster\StandaloneLeadership}.
 */
interface ConsensusInspection
{
    /**
     * @return int Current election term (monotonic, in-memory only)
     */
    public function term(): int;

    /**
     * @return ConsensusRole Current consensus role of the local node
     */
    public function consensusRole(): ConsensusRole;
}
