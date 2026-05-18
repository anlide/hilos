<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\AbstractHilosDashboardPage;

/**
 * DashboardPage - Dashboard page implementation for demo.
 */
final class DashboardPage extends AbstractHilosDashboardPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
