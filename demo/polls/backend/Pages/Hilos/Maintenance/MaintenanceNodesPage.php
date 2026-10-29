<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\Maintenance;

use Demo\Polls\Constants\AgentType;
use Hilos\Pages\Maintenance\AbstractHilosMaintenanceNodesPage;

/**
 * MaintenanceNodesPage - Hilos maintenance nodes page implementation for demo.
 */
final class MaintenanceNodesPage extends AbstractHilosMaintenanceNodesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
