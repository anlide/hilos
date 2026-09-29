<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\Logs;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsViewPage;

/**
 * LogsViewPage - Hilos logs viewer page implementation for the binance-btc-tracker demo.
 */
final class LogsViewPage extends AbstractHilosLogsViewPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
