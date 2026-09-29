<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Core\Agent\Daemon;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * Daemon proxy for the binance-btc-tracker agent.
 */
final class BinanceBtcTrackerAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::BINANCE_BTC_TRACKER;

    /**
     * The app agent owns the connections registry and answers the WebSocket lifecycle, so it
     * must be single-owner.
     *
     * @return bool True because who is on the wire is shared state with one writer
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}
