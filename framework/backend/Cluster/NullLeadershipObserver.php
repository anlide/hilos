<?php

declare(strict_types=1);

namespace Hilos\Cluster;

/**
 * Inert leadership observer used when no observer is registered.
 *
 * The coordinator always fires its transitions into an observer; outside a daemon
 * (or before the daemon registers itself) there is nobody to react, so
 * {@see ClusterContext::leadershipObserver()} returns this no-op rather than a
 * nullable the coordinator would have to guard on every event.
 */
final class NullLeadershipObserver implements LeadershipObserver
{
    public function onBecameLeader(int $term): void
    {
    }

    public function onLostLeadership(int $term): void
    {
    }

    public function onQuorumGained(): void
    {
    }

    public function onQuorumLost(): void
    {
    }
}
