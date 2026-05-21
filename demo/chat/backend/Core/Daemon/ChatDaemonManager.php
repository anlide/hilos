<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Daemon;

use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;

/**
 * ChatDaemonManager - Main daemon manager for chat demo.
 *
 * Extends framework DaemonManager to provide chat functionality.
 * Manages signal routing and server coordination.
 */
final class ChatDaemonManager extends DaemonManager
{
    /**
     * Initializes chat daemon manager.
     *
     * Sets shutdown timeout to 10 seconds.
     * Registers cron rules for chat cleanup and expired attachment drafts.
     */
    public function __construct()
    {
        parent::__construct();

        $this->shutdownTimeout = 10.0;

        // Register cron rule for chat history cleanup (every 30 minutes)
        // Cron expression: "*/30 * * * *" means every 30 minutes
        $this->addCronRule(ChatCronConstants::CLEANUP_HISTORY, '*/30 * * * *');
        $this->addCronRule(ChatCronConstants::CLEANUP_ATTACHMENT_DRAFTS, '*/15 * * * *');
    }

    /**
     * Create signal router instance.
     *
     * @return SignalRouter Chat signal router instance
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new ChatSignalRouter();
    }

    /**
     * Create agent manager daemon instance.
     *
     * @return AgentManagerDaemon Chat agent manager daemon instance
     */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new ChatAgentManagerDaemon();
    }
}
