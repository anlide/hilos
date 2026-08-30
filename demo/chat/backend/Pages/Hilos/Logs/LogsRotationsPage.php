<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Logs;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsRotationsPage;

/**
 * LogsRotationsPage - Hilos logs rotation history page implementation for demo.
 */
final class LogsRotationsPage extends AbstractHilosLogsRotationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
