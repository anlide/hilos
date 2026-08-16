<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Core\Daemon\DaemonManager;
use Hilos\HilosException;

/**
 * Seam that receives cluster leadership and quorum transitions as they happen.
 *
 * The consensus coordinator decides leadership and quorum; the daemon wants to
 * react to the four transitions without polling the seam every tick. Symmetric to
 * {@see MembershipObserver}: {@see ClusterContext} exposes the registered observer
 * to the coordinator, and the {@see DaemonManager} registers
 * itself at start, turning these into project-overridable hooks. The framework only
 * delivers the events — reacting to them (gating singleton work, stopping in-flight
 * duties) is the project's job and lands in the neighbouring cluster slices.
 */
interface LeadershipObserver
{
    /**
     * @param int $term Election term in which leadership was won
     * @throws HilosException Whatever the project's own leadership duties raise
     */
    public function onBecameLeader(int $term): void;

    /**
     * @param int $term Election term in which leadership was held and then lost
     */
    public function onLostLeadership(int $term): void;

    /**
     * Called when the local node gains a quorum of the master set.
     */
    public function onQuorumGained(): void;

    /**
     * Called when the local node loses its quorum of the master set.
     */
    public function onQuorumLost(): void;
}
