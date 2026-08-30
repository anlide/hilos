<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Logs;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsKeysPage;

/**
 * LogsKeysPage - Hilos logs by key list page implementation for demo.
 */
final class LogsKeysPage extends AbstractHilosLogsKeysPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
