<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos\ChangeLog;

use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogFeedPage;

/**
 * ChangeLogFeedPage - Hilos change log feed for the roblox-game-server demo.
 */
final class ChangeLogFeedPage extends AbstractHilosChangeLogFeedPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
