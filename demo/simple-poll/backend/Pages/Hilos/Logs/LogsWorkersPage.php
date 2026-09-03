<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Pages\Hilos\Logs;

use Demo\SimplePoll\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsWorkersPage;

/**
 * LogsWorkersPage - Hilos logs by worker list page implementation for the simple-poll demo.
 */
final class LogsWorkersPage extends AbstractHilosLogsWorkersPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
