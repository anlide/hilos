<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\Communications;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsDeliveriesPage;

/**
 * CommunicationsDeliveriesPage - Channel deliveries stub for the binance-btc-tracker demo.
 */
final class CommunicationsDeliveriesPage extends AbstractHilosCommunicationsDeliveriesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
