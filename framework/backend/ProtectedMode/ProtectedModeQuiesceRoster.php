<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * ProtectedModeQuiesceRoster - who the leader is still waiting on for the freeze it is driving.
 *
 * The one thing about a freeze that the row does not carry and cannot: the outstanding node list
 * is the leader's own bookkeeping ({@see ClusterProtectedMode}), while
 * {@see ProtectedModeRuntime} says what each node is doing rather than what the round is missing.
 * A watchdog reporting a round that never closed needs exactly this list, because "one of your
 * nodes never answered" without naming it leaves the operator to find that node by hand.
 *
 * Implemented by the clustered orchestration only. A single node is answered by an empty list -
 * it has no peers to wait for, so a round that never closes there is not a thing that can happen.
 */
interface ProtectedModeQuiesceRoster
{
    /**
     * Names the nodes whose quiesced report the leader is still waiting for.
     *
     * @return list<string> Node ids that have not reported, empty when none are outstanding
     */
    public function pendingNodeIds(): array;
}
