<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Daemon;

use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\ProtectedMode\ProtectedModeAgentStopSink;

/**
 * AgentStartSink - where the master hears how an agent's start on this node ended, and when the
 * wait for that start ended because the agent was stopped.
 *
 * Shaped on {@see ProtectedModeAgentStopSink} and registered beside it, for the same reason: the
 * one listener has to hear the news as it arrives, not find it in the roster later. That listener
 * is {@see DaemonManager}, which holds frames for an agent whose start has not been reported and
 * lets them go the moment it is (HIL-629), or the moment a stop of that agent ends the wait
 * without answering them (HIL-1041). The freeze watchdog's stop sink still hears every stop; this
 * one is told about a stop so it can let go of frames still held for that agent's start.
 */
interface AgentStartSink
{
    /**
     * Reports that an agent's start on this node finished and its onStart() has returned.
     *
     * Runs on the master's message path, after the roster already marks the agent started, so an
     * implementation reads memory and returns - no database, no blocking call.
     *
     * @param string $agentId Id of the agent that started, in the `type` or `type:index` form
     */
    public function onAgentStarted(string $agentId): void;

    /**
     * Reports that an agent's start on this node did not finish, and that no start report will follow.
     *
     * A separate method rather than a flag on the one above: the two are different news, and the
     * listener does different things with them. Runs on the master's message path, after the
     * roster already forgot the agent, under the same no-blocking rule as its sibling.
     *
     * @param string $agentId Id of the agent whose start failed, in the `type` or `type:index` form
     * @param string $reason Why the start did not finish, as the failure said it
     * @throws InvalidArgumentException When an answer the listener owes a waiting frame cannot be named
     */
    public function onAgentStartFailed(string $agentId, string $reason): void;

    /**
     * Reports that an agent stopped on this node while a start wait for it was still open.
     *
     * A separate method rather than a flag on the two above: the listener does not answer the
     * held frames, it lets them go so they ask the address again. Runs on the master's message
     * path, after the roster already forgot the agent, under the same no-blocking rule as its
     * siblings.
     *
     * @param string $agentId Id of the agent that stopped, in the `type` or `type:index` form
     */
    public function onAgentStopped(string $agentId): void;
}
