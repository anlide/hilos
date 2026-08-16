<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\Server\WorkerServer;

/**
 * ProtectedModeStuckVerdict - why {@see ProtectedModeWatchdog} decided a freeze is not moving.
 *
 * Four ways a node stays frozen with nothing happening behind it, and the watchdog reports exactly
 * one of them: the cases are listed in the order they are evaluated, and the first that holds wins.
 * The order is by certainty rather than by severity - an initiator known to be gone is a fact, a
 * threshold that elapsed is an inference - so the operator reading the alert is told the strongest
 * thing that can be said about the freeze.
 *
 * A verdict never means the watchdog did something about it. Nothing here lifts a freeze, moves a
 * phase or restarts an initiator: opening a node whose database may be half-written is a decision
 * that belongs to a person, and every case below is only ever reported.
 */
enum ProtectedModeStuckVerdict: string
{
    /**
     * The agent that froze the node has stopped, so nothing is running behind the freeze.
     *
     * A fact rather than a threshold, which is why it is judged first and why it sticks for the
     * life of the freeze: {@see WorkerServer::protectedModeRefusesStart()} lets that agent type
     * start again, and a fresh instance would look alive while the operation behind it is gone.
     */
    case INITIATOR_LOST = 'initiator-lost';

    /**
     * The daemon came back up and restored a freeze from disk, so it started no operation behind it.
     *
     * Reported on the first tick rather than after the silence threshold: a restarted node knows
     * with certainty that nothing of its own is running, and waiting out a threshold would only
     * delay telling the operator something already established.
     */
    case RESTORED_FROM_DISK = 'restored-from-disk';

    /**
     * The quiesce round never closed: the freeze sits on {@see ProtectedModeRuntime::PHASE_ACTIVATING}
     * with at least one node that has not reported in.
     *
     * The one verdict about a freeze that never fully took hold. Nothing destructive has run - the
     * initiator was never told to go - which makes it the mildest case here and still not one the
     * watchdog may resolve: only a person decides whether the missing node comes back or the freeze
     * is abandoned.
     */
    case QUIESCE_OVERDUE = 'quiesce-overdue';

    /**
     * The freeze holds and nothing behind it has reported progress inside the silence threshold.
     *
     * The catch-all, and the one that answers "fast restore or long migration" by deleting the
     * question: an operation that is still running says so ({@see ProtectedModeRuntime::$progressAt}),
     * so its length stops being a parameter. An initiator that never marks progress raises a false
     * alarm on an honest long run - the accepted direction of the error, because the alarm is a
     * message and a missed hang would never surface at all.
     */
    case SILENT = 'silent';
}
