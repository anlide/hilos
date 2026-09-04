<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\Logs;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsKeysPage;

/**
 * LogsKeysPage - Hilos logs by key list page implementation for the polls demo.
 */
final class LogsKeysPage extends AbstractHilosLogsKeysPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
