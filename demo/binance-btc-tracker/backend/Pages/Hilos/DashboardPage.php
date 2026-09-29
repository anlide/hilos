<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\AbstractHilosDashboardPage;

/**
 * DashboardPage - Concrete Hilos dashboard page for the binance-btc-tracker demo.
 *
 * The application shell's gear links here; the framework base supplies the
 * subscription behavior and the browser snapshot, so the demo only binds the
 * page to its Hilos index agent.
 */
final class DashboardPage extends AbstractHilosDashboardPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
