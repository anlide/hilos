<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Pages\Hilos\Logs;

use Demo\SimplePoll\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsViewPage;

/**
 * LogsViewPage - Hilos logs viewer page implementation for the simple-poll demo.
 */
final class LogsViewPage extends AbstractHilosLogsViewPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
