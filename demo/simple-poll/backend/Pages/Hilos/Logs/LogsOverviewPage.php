<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Pages\Hilos\Logs;

use Demo\SimplePoll\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsPage;

/**
 * LogsOverviewPage - Hilos logs overview page implementation for the simple-poll demo.
 */
final class LogsOverviewPage extends AbstractHilosLogsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
