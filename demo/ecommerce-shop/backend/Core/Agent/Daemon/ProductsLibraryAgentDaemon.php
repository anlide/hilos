<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Agent\Daemon;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the products library agent.
 */
final class ProductsLibraryAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::PRODUCTS_LIBRARY;

    /**
     * The products library is the one writer of the products and their addresses.
     *
     * @return bool True because product writes must be single-owner
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
