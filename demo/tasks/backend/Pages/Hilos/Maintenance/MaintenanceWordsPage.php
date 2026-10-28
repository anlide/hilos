<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\Maintenance;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\Maintenance\AbstractHilosMaintenanceWordsPage;

/**
 * MaintenanceWordsPage - The words tab served by the tasks demo's hilos index agent.
 */
final class MaintenanceWordsPage extends AbstractHilosMaintenanceWordsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
