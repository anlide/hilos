<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Pages\Hilos\Analytics;

use Demo\HilosWebsite\Constants\AgentType;
use Hilos\Pages\Analytics\AbstractHilosAnalyticsPersonPage;

/** One person's analytics browser sessions, served by the demo's Analytics agent. */
final class PersonPage extends AbstractHilosAnalyticsPersonPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_ANALYTICS;
}
