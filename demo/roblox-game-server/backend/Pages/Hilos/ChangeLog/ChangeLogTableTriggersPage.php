<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos\ChangeLog;

use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTableTriggersPage;

/**
 * ChangeLogTableTriggersPage - Hilos change log table triggers for the roblox-game-server demo.
 */
final class ChangeLogTableTriggersPage extends AbstractHilosChangeLogTableTriggersPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
