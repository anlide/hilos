<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Agent\Daemon;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the warehouses library agent.
 */
final class WarehousesLibraryAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::WAREHOUSES_LIBRARY;

    /**
     * The warehouses library is the one writer of the warehouses table.
     *
     * @return bool True because warehouse CRUD must be single-owner
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
