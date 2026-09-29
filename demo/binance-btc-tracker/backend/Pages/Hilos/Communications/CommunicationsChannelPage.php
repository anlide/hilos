<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\Communications;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelPage;

/**
 * CommunicationsChannelPage - Single channel config for the binance-btc-tracker demo.
 */
final class CommunicationsChannelPage extends AbstractHilosCommunicationsChannelPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
