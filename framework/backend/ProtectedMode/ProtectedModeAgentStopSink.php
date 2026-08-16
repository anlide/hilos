<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Core\Agent\Daemon\AgentManagerDaemon;

/**
 * ProtectedModeAgentStopSink - where the master reports that one of its agents has stopped.
 *
 * The narrow seam {@see AgentManagerDaemon} is given so it can pass the fact on without knowing
 * what protected mode is, mirroring the sinks the cluster context already registers. Exactly one
 * implementation exists ({@see ProtectedModeWatchdog}), and it is the only thing in the framework
 * that has to hear about a stop the moment it happens rather than at the next tick: an initiator
 * agent may be started again while the freeze it left behind still stands, so a watchdog that
 * merely looked at the roster later would see it alive and never learn the operation had died.
 */
interface ProtectedModeAgentStopSink
{
    /**
     * Reports that an agent stopped on this node.
     *
     * Called for every agent, not only for an initiator: which stop matters depends on the freeze
     * row, which the sink reads for itself. Runs on the master's message path, so an implementation
     * reads memory and returns - no database, no blocking call.
     *
     * @param string $agentId Id of the agent that stopped, in the `type` or `type:index` form
     */
    public function onAgentStopped(string $agentId): void;
}
