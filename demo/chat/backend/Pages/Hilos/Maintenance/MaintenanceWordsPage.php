<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Maintenance;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\Maintenance\AbstractHilosMaintenanceWordsPage;

/**
 * MaintenanceWordsPage - Hilos maintenance words page implementation for demo.
 */
final class MaintenanceWordsPage extends AbstractHilosMaintenanceWordsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
