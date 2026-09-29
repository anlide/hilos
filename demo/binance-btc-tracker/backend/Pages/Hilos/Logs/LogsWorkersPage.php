<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\Logs;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsWorkersPage;

/**
 * LogsWorkersPage - Hilos logs by worker list page implementation for the binance-btc-tracker demo.
 */
final class LogsWorkersPage extends AbstractHilosLogsWorkersPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
