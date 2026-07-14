<?php

declare(strict_types=1);

namespace Hilos\Cluster;

/**
 * Coarse lifecycle phase of the local node, derived from cluster mode, the
 * self-declared role, and the leadership seam.
 *
 * The daemon's main loop dispatches its per-iteration work by phase (see
 * DaemonManager::dispatchRoleTick): a standalone node keeps running the app
 * daemon logic it always ran, while a clustered node runs role- and
 * leadership-aware work instead. In this slice (HIL-338) a clustered master is
 * always {@see MasterNoQuorum} — the leadership seam reports no leader and no
 * quorum until the coordinator lands (HIL-339) — so {@see MasterLeader} and
 * {@see MasterFollowerOrCandidate} are declared here but unreachable through
 * {@see ClusterContext} yet.
 */
enum NodeLifecycleState
{
    /** Cluster mode is disabled: a single-node daemon running the app logic directly. */
    case Standalone;

    /** Clustered master that currently holds cluster leadership. */
    case MasterLeader;

    /** Clustered master with quorum that is not the leader — a follower or election candidate. */
    case MasterFollowerOrCandidate;

    /** Clustered master that cannot see a quorum, so no coordination may run. */
    case MasterNoQuorum;

    /** Clustered slave: a data-plane node that carries workers but takes no part in coordination. */
    case Slave;

    /**
     * Resolves the lifecycle phase of an enabled cluster node from its role and
     * the leadership seam. The disabled case ({@see Standalone}) is decided by the
     * caller before a node ever has a role or leadership.
     *
     * @param NodeRole $role Self-declared role of the local node
     * @param Leadership $leadership Leadership seam for the local node
     * @return self Resolved lifecycle phase for the enabled node
     */
    public static function forEnabledNode(NodeRole $role, Leadership $leadership): self
    {
        if ($role === NodeRole::Slave) {
            return self::Slave;
        }

        if (!$leadership->hasQuorum()) {
            return self::MasterNoQuorum;
        }

        return $leadership->amLeader() ? self::MasterLeader : self::MasterFollowerOrCandidate;
    }
}
