<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\Logs;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\Logs\AbstractHilosLogsSettingsPage;

/**
 * LogsSettingsPage - Hilos logging modes page implementation for the binance-btc-tracker demo.
 */
final class LogsSettingsPage extends AbstractHilosLogsSettingsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_LOGS;
}
