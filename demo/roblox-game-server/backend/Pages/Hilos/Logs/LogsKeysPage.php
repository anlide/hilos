<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos\Logs;

use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsKeysPage;

/**
 * LogsKeysPage - Hilos logs by key list page implementation for the roblox-game-server demo.
 */
final class LogsKeysPage extends AbstractHilosLogsKeysPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
