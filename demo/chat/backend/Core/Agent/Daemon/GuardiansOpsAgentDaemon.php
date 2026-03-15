<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * GuardiansOpsAgentDaemon - Daemon proxy for GuardiansOpsAgent.
 *
 * Monopolistic agent for guardian operations. Manages guardian reports and policies.
 */
final class GuardiansOpsAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::GUARDIAN_OPS;

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
