<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\Maintenance;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\Maintenance\AbstractHilosMaintenanceWordsPage;

/**
 * MaintenanceWordsPage - The words tab served by the binance-btc-tracker demo's hilos index agent.
 */
final class MaintenanceWordsPage extends AbstractHilosMaintenanceWordsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
