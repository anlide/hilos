<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\Hilos\Maintenance;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Pages\Maintenance\AbstractHilosMaintenancePage;

/** The maintenance section served by this demo's hilos index agent. */
final class MaintenancePage extends AbstractHilosMaintenancePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
