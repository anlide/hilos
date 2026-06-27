<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Core\Daemon;

use Demo\SimpleTodo\Core\Router\TodoSignalRouter;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;

/**
 * TodoDaemonManager - Main daemon manager for the simple-todo demo.
 *
 * Extends framework DaemonManager to provide demo signal routing and agent
 * daemon management. No cron rules yet.
 */
final class TodoDaemonManager extends DaemonManager
{
    /**
     * Create signal router instance.
     *
     * @return SignalRouter Todo signal router instance
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new TodoSignalRouter();
    }

    /**
     * Create agent manager daemon instance.
     *
     * @return AgentManagerDaemon Todo agent manager daemon instance
     */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new TodoAgentManagerDaemon();
    }
}
