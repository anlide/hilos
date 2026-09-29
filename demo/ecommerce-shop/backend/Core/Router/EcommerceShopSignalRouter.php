<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Router;

use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Router\SignalRouter;

/**
 * EcommerceShopSignalRouter - Signal router for the ecommerce-shop demo.
 *
 * Declares demo service-signal defaults. Page subscription, page actions, and
 * page-owned signals are resolved by framework SignalRouter from project
 * topology.
 */
final class EcommerceShopSignalRouter extends SignalRouter
{
    /**
     * Returns the ecommerce-shop project facade for topology registry reads.
     *
     * @return class-string<Hilos> EcommerceShop project facade class
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }

    /**
     * Returns ecommerce-shop agents started on DAEMON/SYSTEM bootstrap signals.
     *
     * @return list<string> Agent type identifiers
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [
            AgentType::ECOMMERCE_SHOP,
        ];
    }

    /**
     * Returns the ecommerce-shop owner for WebSocket lifecycle service signals.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return AgentType::ECOMMERCE_SHOP;
    }
}
