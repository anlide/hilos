<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\Hilos\Maintenance;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Pages\Maintenance\AbstractHilosMaintenanceNodesPage;

/**
 * MaintenanceNodesPage - Hilos maintenance nodes page implementation for demo.
 */
final class MaintenanceNodesPage extends AbstractHilosMaintenanceNodesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
