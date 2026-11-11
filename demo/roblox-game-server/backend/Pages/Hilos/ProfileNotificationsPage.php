<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos;

use Demo\RobloxGameServer\Agents\RobloxGameServerAgent;
use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileNotificationsPage;

/**
 * Roblox-game-server binding of the framework current-user notification channels page.
 *
 * @property RobloxGameServerAgent $agent
 */
final class ProfileNotificationsPage extends AbstractHilosProfileNotificationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::ROBLOX_GAME_SERVER;
}
