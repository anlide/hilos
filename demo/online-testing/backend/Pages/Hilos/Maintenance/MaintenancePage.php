<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Pages\Hilos\Maintenance;

use Demo\OnlineTesting\Constants\AgentType;
use Hilos\Pages\Maintenance\AbstractHilosMaintenancePage;

/** The maintenance section served by the online-testing demo's hilos index agent. */
final class MaintenancePage extends AbstractHilosMaintenancePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
