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
 * quorum); this narrow interface adds the internal consensus values a test
 * harness and the Daemon section read — the monotonic election term, the current
 * {@see ConsensusRole}, and the static master's quorum view. Only a
 * {@see ClusterCoordinator} carries them, so
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

    /** @return int Masters visible on the coordinator's last tick, or zero before its first tick */
    public function onlineMasterCount(): int;

    /** @return int Masters in the configured static set */
    public function masterSetSize(): int;

    /** @return int Majority required by the configured static set */
    public function quorumSize(): int;
}
