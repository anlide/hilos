<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

/**
 * The leadership hooks of protected mode: a node taking up or dropping the leader-side role.
 *
 * Split away from {@see ProtectedModeSwitch} because only the clustered coordinator has this
 * role at all - a single node leads nothing. The daemon drives these hooks from its own
 * leadership transitions and must not reach for them through the request seam, or every
 * standalone installation would need a pair of no-op methods to satisfy a contract that
 * means nothing there.
 */
interface ProtectedModeLeadership
{
    /**
     * Marks this node the leader; it now owns the freeze orchestration.
     */
    public function onBecameLeader(): void;

    /**
     * Marks this node no longer the leader and drops any orchestration it was driving.
     */
    public function onLostLeadership(): void;
}
