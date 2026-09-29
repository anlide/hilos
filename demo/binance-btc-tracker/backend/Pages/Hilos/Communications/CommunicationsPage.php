<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos\Communications;

use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\Communications\AbstractHilosCommunicationsPage;

/**
 * CommunicationsPage - Hilos communications hub for the binance-btc-tracker demo.
 */
final class CommunicationsPage extends AbstractHilosCommunicationsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}
