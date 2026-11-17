<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages\Hilos;

use Demo\BinanceBtcTracker\Agents\BinanceBtcTrackerAgent;
use Demo\BinanceBtcTracker\Constants\AgentType;
use Hilos\Pages\AbstractHilosProfilePage;

/**
 * Binance BTC Tracker binding of the framework current-user profile page.
 *
 * Binds the app agent that serves it. Account writes remain on the users library; the profile
 * is a reading surface.
 *
 * @property BinanceBtcTrackerAgent $agent
 */
final class ProfilePage extends AbstractHilosProfilePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::BINANCE_BTC_TRACKER;
}
