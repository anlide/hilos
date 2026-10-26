<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\Communications;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelFramePage;

/** Email letter frame subscription served by the binance-btc-tracker demo's index agent. */
final class CommunicationsChannelFramePage extends AbstractHilosCommunicationsChannelFramePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
