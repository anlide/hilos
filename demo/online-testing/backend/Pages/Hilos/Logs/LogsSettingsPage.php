<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Pages\Hilos\Logs;

use Demo\OnlineTesting\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsSettingsPage;

/**
 * LogsSettingsPage - Hilos logging modes page implementation for the online-testing demo.
 */
final class LogsSettingsPage extends AbstractHilosLogsSettingsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
