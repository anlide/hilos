<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\Communications;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelConfigurationPage;

/** Channel configuration subscription served by the binance-btc-tracker demo's index agent. */
final class CommunicationsChannelConfigurationPage extends AbstractHilosCommunicationsChannelConfigurationPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
