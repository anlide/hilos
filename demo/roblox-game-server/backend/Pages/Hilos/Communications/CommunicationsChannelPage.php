<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos\Communications;

use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelPage;

/**
 * CommunicationsChannelPage - Single channel config for the roblox-game-server demo.
 */
final class CommunicationsChannelPage extends AbstractHilosCommunicationsChannelPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
