<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos\Logs;

use Demo\ConcertTickets\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsPage;

/**
 * LogsOverviewPage - Hilos logs overview page implementation for the concert-tickets demo.
 */
final class LogsOverviewPage extends AbstractHilosLogsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
