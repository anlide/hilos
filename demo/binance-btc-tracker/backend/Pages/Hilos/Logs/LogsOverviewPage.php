<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\Logs;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsPage;

/**
 * LogsOverviewPage - Hilos logs overview page implementation for the binance-btc-tracker demo.
 */
final class LogsOverviewPage extends AbstractHilosLogsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
