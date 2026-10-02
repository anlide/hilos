<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Pages\Hilos\Logs;

use Demo\OnlineTesting\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsRotationsPage;

/**
 * LogsRotationsPage - Hilos logs rotation history page implementation for the online-testing demo.
 */
final class LogsRotationsPage extends AbstractHilosLogsRotationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
