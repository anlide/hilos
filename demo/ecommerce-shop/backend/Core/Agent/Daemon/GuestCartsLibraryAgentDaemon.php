<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Agent\Daemon;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the guest carts library agent.
 */
final class GuestCartsLibraryAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::GUEST_CARTS_LIBRARY;

    /**
     * The guest carts library is the one writer of the guests' carts.
     *
     * @return bool True because cart writes must be single-owner
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
