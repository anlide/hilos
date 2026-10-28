<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\ChangeLog;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogTableTriggersPage;

/**
 * ChangeLogTableTriggersPage - Hilos change log table triggers for the binance-btc-tracker demo.
 */
final class ChangeLogTableTriggersPage extends AbstractHilosChangeLogTableTriggersPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
