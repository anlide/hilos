<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\Maintenance;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\Maintenance\AbstractHilosMaintenancePage;

/** The maintenance section served by the polls demo's hilos index agent. */
final class MaintenancePage extends AbstractHilosMaintenancePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
