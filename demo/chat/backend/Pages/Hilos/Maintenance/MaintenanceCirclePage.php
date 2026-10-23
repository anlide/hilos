<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Maintenance;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Maintenance\AbstractHilosMaintenanceCirclePage;

/**
 * MaintenanceCirclePage - Hilos maintenance section implementation for demo.
 */
final class MaintenanceCirclePage extends AbstractHilosMaintenanceCirclePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
