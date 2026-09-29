<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos;

use Demo\BinanceBtcTracker\Agents\BinanceBtcTrackerAgent;
use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileNotificationsPage;

/**
 * Binance-btc-tracker binding of the framework current-user notification channels page.
 *
 * @property BinanceBtcTrackerAgent $agent
 */
final class ProfileNotificationsPage extends AbstractHilosProfileNotificationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::BINANCE_BTC_TRACKER;
}
