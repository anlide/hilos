<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * ProtectedModeQuiesceRoster - participants and outstanding reports of the leader's current round.
 *
 * The selected participants and outstanding reports are the leader's own bookkeeping
 * ({@see ClusterProtectedMode}), while
 * {@see ProtectedModeRuntime} says what each node is doing rather than what the round is missing.
 * A watchdog reporting a round that never closed needs exactly this list, because "one of your
 * nodes never answered" without naming it leaves the operator to find that node by hand.
 *
 * Implemented by the clustered orchestration only. The standalone switch has no peer round.
 */
interface ProtectedModeQuiesceRoster
{
    /**
     * The full participant snapshot survives reports that remove nodes from the pending list.
     *
     * @return ?list<string> Participants selected for this leader's round, or null when it leads none
     */
    public function quiesceNodeIds(): ?array;

    /**
     * Names the nodes whose quiesced report the leader is still waiting for.
     *
     * @return list<string> Node ids that have not reported, empty when none are outstanding
     */
    public function pendingNodeIds(): array;
}
