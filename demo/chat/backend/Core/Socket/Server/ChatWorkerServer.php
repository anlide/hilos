<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Socket\Server;

use Demo\Chat\Hilos;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\Master\MasterFailureUnit;
use Hilos\HilosException;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Utils\Logger;
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
     * Extends the framework startup, including delivery owners, with one agent per active bot.
     * Leader promotion and worker loss repeat this pass; starts are idempotent and each bot's
     * failure is contained so later bots still start.
     *
     * @throws HilosException When the initial-agents signal or project singleton startup fails
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
    }

    /**
     * Starts one agent of the singleton loops, containing its failure the way the framework's
     * per-node start does: the line names the agent, and the project hears it as a card.
     *
     * @param string $agentType Agent type to start
     * @param string $agentIndex Bot index
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
