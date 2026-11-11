<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos\Maintenance;

use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\Maintenance\AbstractHilosMaintenanceCirclePage;

/** The verifier circle served by the roblox-game-server demo's hilos index agent. */
final class MaintenanceCirclePage extends AbstractHilosMaintenanceCirclePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
