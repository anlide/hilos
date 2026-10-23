<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\Maintenance;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\Maintenance\AbstractHilosMaintenanceCirclePage;

/** The verifier circle served by the binance-btc-tracker demo's hilos index agent. */
final class MaintenanceCirclePage extends AbstractHilosMaintenanceCirclePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
