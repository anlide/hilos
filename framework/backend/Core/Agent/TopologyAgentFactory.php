<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\HilosAgentDaemonFactory;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Hilos;

/**
 * TopologyAgentFactory - Creates worker agents and daemon proxies from Hilos::AGENTS.
 */
final class TopologyAgentFactory
{
    /**
     * Creates a worker agent from the project topology registry.
     *
     * @param class-string<Hilos> $hilosClass Active project Hilos facade class
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (required when registry entry sets INDEXED)
     * @return AgentInterface Agent instance
     * @throws AgentIndexRequiredException When INDEXED is true and agentIndex is null
     * @throws AgentCreationFailedException When agent type is unknown
     */
    public static function createWorker(string $hilosClass, string $agentType, ?string $agentIndex): AgentInterface
    {
        $entry = $hilosClass::AGENTS[$agentType] ?? null;
        $workerClass = AgentRegistry::workerClass($entry);
        if ($workerClass === null) {
            return HilosAgentWorkerFactory::createAgent($agentType, $agentIndex);
        }

        if (AgentRegistry::requiresIndex($entry)) {
            return new $workerClass(
                $agentIndex ?? throw new AgentIndexRequiredException(
                    "{$workerClass} requires agentIndex",
                ),
            );
        }

        return new $workerClass();
    }

    /**
     * Creates a daemon agent proxy from the project topology registry.
     *
     * @param class-string<Hilos> $hilosClass Active project Hilos facade class
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (required when registry entry sets INDEXED)
     * @return AgentDaemonInterface Agent daemon instance
     * @throws AgentIndexRequiredException When INDEXED is true and agentIndex is null
     * @throws AgentDaemonCreationFailedException When agent type is unknown
     */
    public static function createDaemon(string $hilosClass, string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        $entry = $hilosClass::AGENTS[$agentType] ?? null;
        $daemonClass = AgentRegistry::daemonClass($entry);
        if ($daemonClass === null) {
            return HilosAgentDaemonFactory::createAgentDaemon($agentType, $agentIndex);
        }

        if (AgentRegistry::requiresIndex($entry)) {
            return new $daemonClass(
                $agentIndex ?? throw new AgentIndexRequiredException(
                    "{$daemonClass} requires agentIndex",
                ),
            );
        }

        return new $daemonClass();
    }
}
