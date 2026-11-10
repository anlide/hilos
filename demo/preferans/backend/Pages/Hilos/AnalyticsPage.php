<?php

declare(strict_types=1);

namespace Demo\Preferans\Pages\Hilos;

use Demo\Preferans\Constants\AgentType;
use Hilos\Pages\AbstractHilosAnalyticsPage;

/** Analytics section page for the preferans demo. */
final class AnalyticsPage extends AbstractHilosAnalyticsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_ANALYTICS;
}
