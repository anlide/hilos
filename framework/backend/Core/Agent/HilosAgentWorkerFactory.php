<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Core\Agent\Exception\AgentCreationFailedException;

/**
 * HilosAgentWorkerFactory - Factory for creating framework-level agents in worker processes.
 *
 * Extension point for framework-level agent types.
 * Project-level factories should extend this class and delegate default case to parent.
 */
class HilosAgentWorkerFactory extends AbstractAgentWorkerFactory
{
    public static function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        throw new AgentCreationFailedException($agentType, $agentIndex);
    }
}
