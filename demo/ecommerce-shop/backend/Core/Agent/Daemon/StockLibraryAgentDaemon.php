<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Agent\Daemon;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the stock library agent.
 */
final class StockLibraryAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::STOCK_LIBRARY;

    /**
     * The stock library is the one writer of the stock.
     *
     * @return bool True because stock writes must be single-owner
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
