<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\ChangeLog;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\ChangeLog\AbstractHilosChangeLogFeedPage;

/**
 * ChangeLogFeedPage - Hilos change log feed for the binance-btc-tracker demo.
 */
final class ChangeLogFeedPage extends AbstractHilosChangeLogFeedPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_CHANGE_LOG;
}
