<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos;

use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileDataPage;

/** Binds the framework's personal-data page to the roblox-game-server agent. */
final class ProfileDataPage extends AbstractHilosProfileDataPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::ROBLOX_GAME_SERVER;
}
