<?php

declare(strict_types=1);

namespace Hilos\Cluster\Consensus;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Leadership;
use Hilos\Cluster\PendingLeadership;
use Hilos\Cluster\StandaloneLeadership;

/**
 * Read-only window into a consensus coordinator's term and role.
 *
 * The {@see Leadership} seam exposes only the caller-facing verdicts (leader /
 * quorum); this narrow interface adds the two internal consensus values a test
 * harness asserts on — the monotonic election term and the current
 * {@see ConsensusRole}. Only a {@see ClusterCoordinator} carries them, so
 * {@see ClusterContext::inspect()} reports them as null on a node whose leadership
 * seam is an inert {@see PendingLeadership} or {@see StandaloneLeadership}.
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
