<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Socket\Server;

use Demo\Chat\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
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
     * Then places the `hilos_mail` and `hilos_sms` pools: MAIL_WORKER_COUNT / SMS_WORKER_COUNT
     * indexed shards (1..N) each. Both delivery daemons are neither monopolistic nor
     * leader-pinned, so the leader's best-fit placement spreads these shards across the
     * cluster; the loops stay idempotent because startAgent() skips a shard that is already
     * running.
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

        $mailWorkerCount = max(1, Hilos::$env?->int(EnvConstants::MAIL_WORKER_COUNT) ?? 1);
        for ($shard = 1; $shard <= $mailWorkerCount; $shard++) {
            $this->startAgent(HilosAgentType::HILOS_MAIL, (string) $shard);
        }

        $smsWorkerCount = max(1, Hilos::$env?->int(EnvConstants::SMS_WORKER_COUNT) ?? 1);
        for ($shard = 1; $shard <= $smsWorkerCount; $shard++) {
            $this->startAgent(HilosAgentType::HILOS_SMS, (string) $shard);
        }
    }
}
