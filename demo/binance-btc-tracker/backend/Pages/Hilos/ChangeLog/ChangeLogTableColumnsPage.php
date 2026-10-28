<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\ChangeLog;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTableColumnsPage;

/**
 * ChangeLogTableColumnsPage - Hilos change log table columns for the binance-btc-tracker demo.
 */
final class ChangeLogTableColumnsPage extends AbstractHilosChangeLogTableColumnsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
