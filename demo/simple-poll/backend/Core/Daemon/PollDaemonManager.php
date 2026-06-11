<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Core\Daemon;

use Demo\SimplePoll\Core\Router\PollSignalRouter;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;

/**
 * PollDaemonManager - Main daemon manager for the simple-poll demo.
 *
 * Extends framework DaemonManager to provide demo signal routing and agent
 * daemon management. No cron rules yet.
 */
final class PollDaemonManager extends DaemonManager
{
    /**
     * Create signal router instance.
     *
     * @return SignalRouter Poll signal router instance
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new PollSignalRouter();
    }

    /**
     * Create agent manager daemon instance.
     *
     * @return AgentManagerDaemon Poll agent manager daemon instance
     */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new PollAgentManagerDaemon();
    }
}
