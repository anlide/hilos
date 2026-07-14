<?php

declare(strict_types=1);

namespace Hilos\Cluster;

/**
 * Read-only view of the local node's place in cluster coordination.
 *
 * This is the seam every caller uses to ask "am I the leader, who is, do we have
 * a quorum" without depending on how leadership is decided. In this slice
 * (HIL-338) two trivial implementations cover the only answers a node can give
 * before consensus exists: {@see StandaloneLeadership} for a single-node daemon
 * and {@see PendingLeadership} for a clustered node with no coordinator yet. The
 * real election/quorum implementation lands behind this same interface in
 * HIL-339, so callers are untouched when it is swapped in.
 */
interface Leadership
{
    /**
     * @return bool True when the local node currently holds cluster leadership
     */
    public function amLeader(): bool;

    /**
     * @return ?string Node id of the current leader, or null when none is known
     */
    public function leaderId(): ?string;

    /**
     * @return bool True when a quorum of masters is currently visible
     */
    public function hasQuorum(): bool;
}
