<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos\Logs;

use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsViewPage;

/**
 * LogsViewPage - Hilos logs viewer page implementation for the roblox-game-server demo.
 */
final class LogsViewPage extends AbstractHilosLogsViewPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
