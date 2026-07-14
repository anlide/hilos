<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Socket\Server;

use Demo\Chat\Hilos;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Socket\Server\WorkerServer;

/**
 * ChatWorkerServer - Worker server with chat-specific agent daemon factory.
 *
 * Extends WorkerServer to provide chat-specific agent daemon creation.
 */
final class ChatWorkerServer extends WorkerServer
{
    /**
     * Called when server is started. Workers are not ready yet.
     */
    protected function onStart(): void
    {
        // Server initialization - workers are not ready yet
    }

    /**
     * Starts the chat cluster-singleton agents on the leader node.
     *
     * Calls parent to queue INITIAL_AGENTS_START, then starts a BotAgent for each
     * active bot. Bots are cluster-singletons (leader-only by default), so the
     * per-bot startAgent() is a no-op on a follower; the daemon re-runs this on
     * promotion, so it must stay idempotent (startAgent() skips a running agent).
     *
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     * @throws NoSuitableWorkerException If no suitable worker is available
     */
    public function onBecameSingletonHost(): void
    {
        parent::onBecameSingletonHost();

        foreach (Hilos::$db->bots as $bot) {
            if (!$bot->active) {
                continue;
            }
            $this->startAgent('bot', (string) $bot->id);
        }
    }
}
