<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Utils\Logger;

/**
 * GuardiansOpsAgentDaemon - Daemon proxy for GuardiansOpsAgent
 *
 * Monopolistic agent for guardian operations. Manages guardian reports and policies.
 */
final class GuardiansOpsAgentDaemon extends AbstractAgentDaemon
{
    private const string AGENT_TYPE = AgentType::GUARDIAN_OPS;

    /**
     * Creates daemon proxy for GuardiansOpsAgent.
     */
    public function __construct()
    {
        Logger::debug('GuardiansOpsAgentDaemon created [type=' . self::AGENT_TYPE . ']');
    }

    /**
     * Get agent type identifier.
     *
     * @return string Agent type
     */
    public function getType(): string
    {
        return self::AGENT_TYPE;
    }

    /**
     * Get agent index (null for global guardian ops agent).
     *
     * @return ?string Agent index or null
     */
    public function getIndex(): ?string
    {
        return null;
    }

    /**
     * Check if agent requires monopolistic worker process.
     *
     * @return bool True (guardian ops agent requires monopolistic worker)
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
