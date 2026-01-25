<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Daemon;

use Demo\WebSocketTest\Constants\ChatCronConstants;
use Demo\WebSocketTest\Core\Router\ChatSignalRouter;
use Demo\WebSocketTest\DTO\CronSignalDTO;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;

/**
 * ChatDaemonManager - Main daemon manager for chat demo
 *
 * Extends framework DaemonManager to provide chat functionality.
 * Implements tick() method for project-specific logic.
 * Manages signal routing and server coordination.
 */
class ChatDaemonManager extends DaemonManager
{
    /**
     * Constructor
     *
     * Sets shutdown timeout to 10 seconds.
     * Registers cron rules for chat cleanup (every 5 minutes).
     */
    public function __construct()
    {
        parent::__construct();

        $this->shutdownTimeout = 10.0;

        // Register cron rule for chat history cleanup (every 5 minutes)
        // Cron expression: "*/5 * * * *" means every 5 minutes
        $this->addCronRule(ChatCronConstants::CLEANUP_HISTORY, '*/5 * * * *');
    }

    /**
     * Create signal router instance
     *
     * @return SignalRouter Signal router instance
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new ChatSignalRouter();
    }

    /**
     * Create agent manager daemon instance
     *
     * @param SignalRouter $signalRouter Signal router instance
     * @return AgentManagerDaemon Agent manager daemon instance
     */
    protected function createAgentManagerDaemon(SignalRouter $signalRouter): AgentManagerDaemon
    {
        return new ChatAgentManagerDaemon($signalRouter);
    }

    /**
     * Daemon tick implementation
     *
     * This is where project-specific logic would be implemented.
     */
    protected function onTick(): void
    {
        // TODO: Add chat-specific logic here
        // - Check WebSocket connections
        // - Process messages
        // - Manage agents/workers if needed
    }

    /**
     * Called when a cron job should be executed
     *
     * Sends cron signal to appropriate agent via signal router.
     *
     * @param CronRule $rule Cron rule that should be executed
     */
    protected function onCron(CronRule $rule): void
    {
        $dto = new CronSignalDTO(
            cronName: $rule->name,
        );

        $this->signalRouter->queueSignal(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::CRON),
            new SignalName($rule->name),
            $dto,
        );
    }
}
