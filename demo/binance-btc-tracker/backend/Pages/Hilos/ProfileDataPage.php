<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfileDataPage;

/** Binds the framework's personal-data page to the binance-btc-tracker agent. */
final class ProfileDataPage extends AbstractHilosProfileDataPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::BINANCE_BTC_TRACKER;
}
