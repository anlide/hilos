<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Socket\Server;

use Demo\Chat\Hilos;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Socket\Server\WorkerServer;

/**
 * ChatWorkerServer - Worker server with chat-specific agent daemon factory
 *
 * Extends WorkerServer to provide chat-specific agent daemon creation.
 */
class ChatWorkerServer extends WorkerServer
{
    /**
     * Called when server is started. Workers are not ready yet.
     */
    protected function onStart(): void
    {
        // Server initialization - workers are not ready yet
    }

    /**
     * Called when initial workers are ready
     *
     * Calls parent to queue INITIAL_AGENTS_START, then starts BotAgent for each active bot.
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     * @throws NoSuitableWorkerException If no suitable worker is available
     */
    protected function onInitialWorkersReady(): void
    {
        parent::onInitialWorkersReady();

        foreach (Hilos::$db->bots as $bot) {
            if (!$bot->active) {
                continue;
            }
            $this->startAgent('bot', (string) $bot->id);
        }
    }
}
