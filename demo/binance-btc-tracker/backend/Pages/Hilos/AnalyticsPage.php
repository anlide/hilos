<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\AbstractHilosAnalyticsPage;

/** Analytics section page for the binance-btc-tracker demo. */
final class AnalyticsPage extends AbstractHilosAnalyticsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_ANALYTICS;
}
