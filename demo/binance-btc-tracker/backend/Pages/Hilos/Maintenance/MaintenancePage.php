<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\Maintenance;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\Maintenance\AbstractHilosMaintenancePage;

/** The maintenance section served by the binance-btc-tracker demo's hilos index agent. */
final class MaintenancePage extends AbstractHilosMaintenancePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
