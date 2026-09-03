<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\Logs;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsWorkersPage;

/**
 * LogsWorkersPage - Hilos logs by worker list page implementation for the tasks demo.
 */
final class LogsWorkersPage extends AbstractHilosLogsWorkersPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
