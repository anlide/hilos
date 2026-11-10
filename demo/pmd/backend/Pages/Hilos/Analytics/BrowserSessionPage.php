<?php

declare(strict_types=1);

namespace Demo\Pmd\Pages\Hilos\Analytics;

use Demo\Pmd\Constants\AgentType;
use Hilos\Pages\Analytics\AbstractHilosAnalyticsBrowserSessionPage;

/** Browser-session detail served by the demo's Analytics agent. */
final class BrowserSessionPage extends AbstractHilosAnalyticsBrowserSessionPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_ANALYTICS;
}
