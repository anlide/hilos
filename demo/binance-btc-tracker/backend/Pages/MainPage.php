<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Pages;

use Demo\BinanceBtcTracker\Agents\BinanceBtcTrackerAgent;
use Demo\BinanceBtcTracker\Constants\AgentType;
use Demo\BinanceBtcTracker\Constants\PageConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageReach;

/**
 * MainPage - The home page of the binance-btc-tracker demo.
 *
 * Declares no actions or signals: the home says who is looking and nothing more, and the name
 * it shows comes with the session identity every page already has. The showcase this page
 * becomes arrives with its own leaf (HIL-159).
 *
 * @property BinanceBtcTrackerAgent $agent
 */
final class MainPage extends AbstractPage
{
    public const string PAGE = PageConstants::MAIN;

    public const PageReach REACH = PageReach::ROUTE;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::BINANCE_BTC_TRACKER;
}
