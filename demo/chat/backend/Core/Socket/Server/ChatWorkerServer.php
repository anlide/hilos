<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Socket\Server;

use Demo\Chat\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\Master\MasterFailureUnit;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Utils\Logger;
use InvalidArgumentException;
use Throwable;

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
     * promotion and after a worker dies hosting agents, so it must stay idempotent
     * (startAgent() skips a running agent); that re-run is what brings back a bot
     * or a shard whose worker died.
     *
     * Then places the `hilos_mail` and `hilos_sms` pools: MAIL_WORKER_COUNT / SMS_WORKER_COUNT
     * indexed shards (1..N) each. Both delivery daemons are neither monopolistic nor
     * leader-pinned, so the leader's best-fit placement spreads these shards across the
     * cluster; the loops stay idempotent because startAgent() skips a shard that is already
     * running.
     *
     * Every start is contained on its own (HIL-999): one bot or shard without a free worker
     * costs that agent, not the bots and shards behind it in the loops.
     *
     * @throws InvalidArgumentException When the initial-agents signal cannot be named
     */
    public function onBecameSingletonHost(): void
    {
        parent::onBecameSingletonHost();

        foreach (Hilos::$db->bots as $bot) {
            if (!$bot->active) {
                continue;
            }
            $this->startSingleton('bot', (string) $bot->id);
        }

        $env = Hilos::$env;
        $mailWorkerCount = $env === null ? 1 : max(1, $env[EnvConstants::MAIL_WORKER_COUNT]->int());
        for ($shard = 1; $shard <= $mailWorkerCount; $shard++) {
            $this->startSingleton(HilosAgentType::HILOS_MAIL, (string) $shard);
        }

        $smsWorkerCount = $env === null ? 1 : max(1, $env[EnvConstants::SMS_WORKER_COUNT]->int());
        for ($shard = 1; $shard <= $smsWorkerCount; $shard++) {
            $this->startSingleton(HilosAgentType::HILOS_SMS, (string) $shard);
        }
    }

    /**
     * Starts one agent of the singleton loops, containing its failure the way the framework's
     * per-node start does: the line names the agent, and the project hears it as a card.
     *
     * @param string $agentType Agent type to start
     * @param string $agentIndex Index of the bot or shard
     */
    private function startSingleton(string $agentType, string $agentIndex): void
    {
        try {
            $this->startAgent($agentType, $agentIndex);
        } catch (Throwable $throwable) {
            $agentId = $this->buildAgentId($agentType, $agentIndex);
            Logger::error("Failed to start cluster-singleton agent {$agentId}: " . $throwable->getMessage());
            $this->reportContainedFailure(new ContainedFailure(MasterFailureUnit::AGENT_START, $agentId, $throwable));
        }
    }
}
