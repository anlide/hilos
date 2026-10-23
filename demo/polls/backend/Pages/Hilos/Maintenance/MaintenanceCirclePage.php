<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\Maintenance;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\Maintenance\AbstractHilosMaintenanceCirclePage;

/** The verifier circle served by the polls demo's hilos index agent. */
final class MaintenanceCirclePage extends AbstractHilosMaintenanceCirclePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
