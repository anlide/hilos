<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\ChangeLog;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogNoisyPage;

/**
 * ChangeLogNoisyPage - Hilos change log noisy for the binance-btc-tracker demo.
 */
final class ChangeLogNoisyPage extends AbstractHilosChangeLogNoisyPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
