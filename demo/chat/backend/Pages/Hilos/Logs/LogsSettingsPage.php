<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Logs;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsSettingsPage;

/**
 * LogsSettingsPage - Hilos logging modes page implementation for demo.
 */
final class LogsSettingsPage extends AbstractHilosLogsSettingsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
