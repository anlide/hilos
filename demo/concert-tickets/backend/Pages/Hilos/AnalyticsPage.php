<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos;

use Demo\ConcertTickets\Constants\AgentType;
use Hilos\Pages\AbstractHilosAnalyticsPage;

/** Analytics section page for the concert-tickets demo. */
final class AnalyticsPage extends AbstractHilosAnalyticsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_ANALYTICS;
}
