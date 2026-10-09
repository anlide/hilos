<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Pages\Hilos;

use Demo\OnlineTesting\Constants\AgentType;
use Hilos\Pages\AbstractHilosAnalyticsPage;

/** Analytics section page for the online-testing demo. */
final class AnalyticsPage extends AbstractHilosAnalyticsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_ANALYTICS;
}
