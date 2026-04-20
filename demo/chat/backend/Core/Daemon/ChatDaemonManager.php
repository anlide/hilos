<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Daemon;

use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Core\Router\ChatSignalMapper;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\Worker\DTO\CronSignalDTO;

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
     * Registers cron rules for chat cleanup (every 30 minutes).
     */
    public function __construct()
    {
        parent::__construct();

        $this->shutdownTimeout = 10.0;

        // Register cron rule for chat history cleanup (every 30 minutes)
        // Cron expression: "*/30 * * * *" means every 30 minutes
        $this->addCronRule(ChatCronConstants::CLEANUP_HISTORY, '*/30 * * * *');
    }

    /**
     * Create signal router instance.
     *
     * @return SignalRouter Chat signal router instance
     */
    protected function createSignalRouter(): SignalRouter
    {
        $router = new ChatSignalRouter();
        $router->setEmitMapper(new ChatSignalMapper());

        return $router;
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

    /**
     * Called when a cron job should be executed.
     *
     * Sends cron signal to appropriate agent via signal router.
     *
     * @param CronRule $rule Cron rule to execute
     */
    protected function onCron(CronRule $rule): void
    {
        $dto = new CronSignalDTO(
            cronName: $rule->name,
        );

        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::CRON),
            new SignalName($rule->name),
            $dto,
        );
    }
}
