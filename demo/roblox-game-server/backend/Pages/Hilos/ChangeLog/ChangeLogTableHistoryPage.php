<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos\ChangeLog;

use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTableHistoryPage;

/**
 * ChangeLogTableHistoryPage - Hilos change log table history for the roblox-game-server demo.
 */
final class ChangeLogTableHistoryPage extends AbstractHilosChangeLogTableHistoryPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
