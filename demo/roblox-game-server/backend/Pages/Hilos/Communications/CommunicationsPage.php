<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos\Communications;

use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsPage;

/**
 * CommunicationsPage - Hilos communications hub for the roblox-game-server demo.
 */
final class CommunicationsPage extends AbstractHilosCommunicationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
