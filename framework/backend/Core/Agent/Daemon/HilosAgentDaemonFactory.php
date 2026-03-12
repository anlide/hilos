<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Daemon;

use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;

/**
 * HilosAgentDaemonFactory - Factory for creating framework-level agent daemon proxies.
 *
 * Extension point for framework-level agent daemon types.
 * Project-level factories should extend this class and delegate default case to parent.
 */
class HilosAgentDaemonFactory extends AbstractAgentDaemonFactory
{
    public static function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException($agentType, $agentIndex);
    }
}
