<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Agent\Daemon;

use Demo\Chat\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the admin library agent.
 */
final class LibraryAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::LIBRARY;

    /**
     * Library agent owns shared admin catalog DB data.
     *
     * @return bool True because library CRUD must be single-owner
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
