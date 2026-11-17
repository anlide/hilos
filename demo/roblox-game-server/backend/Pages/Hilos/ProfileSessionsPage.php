<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos;

use Demo\RobloxGameServer\Agents\RobloxGameServerAgent;
use Demo\RobloxGameServer\Browser\List\ProfileSessionsBrowserList;
use Demo\RobloxGameServer\Constants\AgentType;
use Demo\RobloxGameServer\Database\RobloxGameServerDbContext;
use Hilos\Pages\AbstractHilosProfileSessionsPage;

/**
 * Roblox-game-server binding of the framework current-user sessions page.
 *
 * @property RobloxGameServerAgent $agent
 */
final class ProfileSessionsPage extends AbstractHilosProfileSessionsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::ROBLOX_GAME_SERVER;
    public const array READS_DB = [...parent::READS_DB, RobloxGameServerDbContext::sessions];
    public const array REQUIRED_LISTS = [...parent::REQUIRED_LISTS, ProfileSessionsBrowserList::class];
}
