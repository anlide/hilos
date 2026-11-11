<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos\ChangeLog;

use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogDashboardPage;

/**
 * ChangeLogDashboardPage - Hilos change log dashboard for the roblox-game-server demo.
 */
final class ChangeLogDashboardPage extends AbstractHilosChangeLogDashboardPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
