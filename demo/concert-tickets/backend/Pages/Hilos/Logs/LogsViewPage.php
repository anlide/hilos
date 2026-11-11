<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos\Logs;

use Demo\ConcertTickets\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsViewPage;

/**
 * LogsViewPage - Hilos logs viewer page implementation for the concert-tickets demo.
 */
final class LogsViewPage extends AbstractHilosLogsViewPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
