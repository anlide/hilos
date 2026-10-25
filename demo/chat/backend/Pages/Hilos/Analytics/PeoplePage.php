<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Analytics;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Analytics\AbstractHilosAnalyticsPeoplePage;

/** People analytics subscription, served by the demo's Analytics agent. */
final class PeoplePage extends AbstractHilosAnalyticsPeoplePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_ANALYTICS;
}
