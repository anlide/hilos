<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Pages\Hilos;

use Demo\HilosWebsite\Constants\AgentType;
use Hilos\Pages\AbstractHilosAnalyticsPage;

/** Analytics section page for the hilos-website demo. */
final class AnalyticsPage extends AbstractHilosAnalyticsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_ANALYTICS;
}
