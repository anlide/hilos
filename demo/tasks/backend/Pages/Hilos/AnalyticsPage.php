<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\AbstractHilosAnalyticsPage;

/** Analytics section page for the tasks demo. */
final class AnalyticsPage extends AbstractHilosAnalyticsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_ANALYTICS;
}
