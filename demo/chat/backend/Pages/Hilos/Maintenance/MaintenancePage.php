<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Maintenance;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Maintenance\AbstractHilosMaintenancePage;

/**
 * MaintenancePage - Hilos maintenance section implementation for demo.
 */
final class MaintenancePage extends AbstractHilosMaintenancePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
