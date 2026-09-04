<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\Logs;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsViewPage;

/**
 * LogsViewPage - Hilos logs viewer page implementation for the polls demo.
 */
final class LogsViewPage extends AbstractHilosLogsViewPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
