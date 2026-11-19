<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Agent\Daemon;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the categories library agent.
 */
final class CategoriesLibraryAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::CATEGORIES_LIBRARY;

    /**
     * The categories library is the one writer of the categories and their addresses.
     *
     * @return bool True because category CRUD must be single-owner
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
