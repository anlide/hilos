<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Pages\Hilos\Logs;

use Demo\OnlineTesting\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsPage;

/**
 * LogsOverviewPage - Hilos logs overview page implementation for the online-testing demo.
 */
final class LogsOverviewPage extends AbstractHilosLogsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
