<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\Maintenance;

use Demo\Tasks\Constants\AgentType;
use Hilos\Pages\Maintenance\AbstractHilosMaintenanceCirclePage;

/** The verifier circle served by the tasks demo's hilos index agent. */
final class MaintenanceCirclePage extends AbstractHilosMaintenanceCirclePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
