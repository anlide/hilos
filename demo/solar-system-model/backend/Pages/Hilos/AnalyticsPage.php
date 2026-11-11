<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel\Pages\Hilos;

use Demo\SolarSystemModel\Constants\AgentType;
use Hilos\Pages\AbstractHilosAnalyticsPage;

/** Analytics section page for the Solar System Model demo. */
final class AnalyticsPage extends AbstractHilosAnalyticsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_ANALYTICS;
}
