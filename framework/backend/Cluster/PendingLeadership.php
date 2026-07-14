<?php

declare(strict_types=1);

namespace Hilos\Cluster;

/**
 * Leadership for a clustered node before any coordinator exists.
 *
 * This is the deliberately inert answer HIL-338 gives a clustered node: no
 * leader, no known leader id, no quorum. It keeps cluster masters dormant
 * (lifecycle phase MasterNoQuorum, no coordination work) until the consensus
 * coordinator replaces it in HIL-339, so enabling cluster mode carries no
 * behavioural change on its own.
 */
final class PendingLeadership implements Leadership
{
    /**
     * @return bool Always false: no node leads until the coordinator lands
     */
    public function amLeader(): bool
    {
        return false;
    }

    /**
     * @return ?string Always null: no leader is known before consensus exists
     */
    public function leaderId(): ?string
    {
        return null;
    }

    /**
     * @return bool Always false: quorum is undecided until the coordinator lands
     */
    public function hasQuorum(): bool
    {
        return false;
    }
}
