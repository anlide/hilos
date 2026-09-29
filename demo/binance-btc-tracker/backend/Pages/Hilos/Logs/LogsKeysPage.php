<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\Logs;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsKeysPage;

/**
 * LogsKeysPage - Hilos logs by key list page implementation for the binance-btc-tracker demo.
 */
final class LogsKeysPage extends AbstractHilosLogsKeysPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
