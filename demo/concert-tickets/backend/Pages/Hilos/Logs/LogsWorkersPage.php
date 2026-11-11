<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Pages\Hilos\Logs;

use Demo\ConcertTickets\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsWorkersPage;

/**
 * LogsWorkersPage - Hilos logs by worker list page implementation for the concert-tickets demo.
 */
final class LogsWorkersPage extends AbstractHilosLogsWorkersPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
