<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Logs;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsViewPage;

/**
 * LogsViewPage - Hilos logs viewer page implementation for demo.
 */
final class LogsViewPage extends AbstractHilosLogsViewPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
