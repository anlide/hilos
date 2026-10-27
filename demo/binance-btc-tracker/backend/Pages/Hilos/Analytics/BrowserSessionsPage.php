<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\Analytics;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\Analytics\AbstractHilosAnalyticsBrowserSessionsPage;

/** Browser sessions analytics subscription, served by the demo's Analytics agent. */
final class BrowserSessionsPage extends AbstractHilosAnalyticsBrowserSessionsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_ANALYTICS;
}
