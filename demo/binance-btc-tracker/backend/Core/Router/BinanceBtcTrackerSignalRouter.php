<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Core\Router;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Demo\BinanceBtcTracker\Hilos;
use Hilos\Core\Router\SignalRouter;

/**
 * BinanceBtcTrackerSignalRouter - Signal router for the binance-btc-tracker demo.
 *
 * Declares demo service-signal defaults. Page subscription, page actions, and
 * page-owned signals are resolved by framework SignalRouter from project
 * topology.
 */
final class BinanceBtcTrackerSignalRouter extends SignalRouter
{
    /**
     * Returns the binance-btc-tracker project facade for topology registry reads.
     *
     * @return class-string<Hilos> BinanceBtcTracker project facade class
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }

    /**
     * Returns binance-btc-tracker agents started on DAEMON/SYSTEM bootstrap signals.
     *
     * @return list<string> Agent type identifiers
     */
    protected function getDefaultSystemBootstrapAgentTypes(): array
    {
        return [
            AgentType::BINANCE_BTC_TRACKER,
        ];
    }

    /**
     * Returns the binance-btc-tracker owner for WebSocket lifecycle service signals.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return AgentType::BINANCE_BTC_TRACKER;
    }
}
