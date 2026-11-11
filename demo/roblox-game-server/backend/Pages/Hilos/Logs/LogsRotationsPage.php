<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos\Logs;

use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsRotationsPage;

/**
 * LogsRotationsPage - Hilos logs rotation history page implementation for the roblox-game-server demo.
 */
final class LogsRotationsPage extends AbstractHilosLogsRotationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
