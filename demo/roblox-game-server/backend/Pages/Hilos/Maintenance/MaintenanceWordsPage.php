<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Pages\Hilos\Maintenance;

use Demo\RobloxGameServer\Constants\AgentType;
use Hilos\Pages\Maintenance\AbstractHilosMaintenanceWordsPage;

/**
 * MaintenanceWordsPage - The words tab served by the roblox-game-server demo's hilos index agent.
 */
final class MaintenanceWordsPage extends AbstractHilosMaintenanceWordsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
