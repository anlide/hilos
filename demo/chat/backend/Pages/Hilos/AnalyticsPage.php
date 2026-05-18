<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\AbstractHilosAnalyticsPage;

/**
 * AnalyticsPage - Analytics page implementation for demo.
 */
final class AnalyticsPage extends AbstractHilosAnalyticsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_ANALYTICS;
}
