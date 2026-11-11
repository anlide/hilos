<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos;

use Demo\RobloxGameServer\Agents\RobloxGameServerAgent;
use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfilePage;

/**
 * Roblox Game Server binding of the framework current-user profile page.
 *
 * Binds the app agent and its self-connection for the permitted live name.
 * Account writes remain on the users library; the profile is a reading surface.
 *
 * @property RobloxGameServerAgent $agent
 */
final class ProfilePage extends AbstractHilosProfilePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::ROBLOX_GAME_SERVER;
}
