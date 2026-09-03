<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Pages\Hilos\Logs;

use Demo\SimplePoll\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsSettingsPage;

/**
 * LogsSettingsPage - Hilos logging modes page implementation for the simple-poll demo.
 */
final class LogsSettingsPage extends AbstractHilosLogsSettingsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
