<?php

declare(strict_types=1);

namespace Hilos\Cluster;

/**
 * Leadership for a single-node daemon: it is always its own leader and always
 * has a quorum, because there is no one else to coordinate with.
 *
 * {@see leaderId()} is null rather than the local node id: a standalone daemon
 * carries no node identity (cluster mode is off), so there is no id to report.
 */
final class StandaloneLeadership implements Leadership
{
    /**
     * @return bool Always true: a lone node leads itself
     */
    public function amLeader(): bool
    {
        return true;
    }

    /**
     * @return ?string Always null: a standalone daemon has no node id to report
     */
    public function leaderId(): ?string
    {
        return null;
    }

    /**
     * @return bool Always true: a single node is a trivial quorum
     */
    public function hasQuorum(): bool
    {
        return true;
    }
}
