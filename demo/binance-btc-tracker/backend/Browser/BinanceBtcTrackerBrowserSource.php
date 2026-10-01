<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Browser;

use Demo\BinanceBtcTracker\Database\BinanceBtcTrackerDbContext;
use Demo\BinanceBtcTracker\Runtime\View\Context\BinanceBtcTrackerRtContext;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;

/**
 * DB and RT sources used by this demo's browser configs.
 */
final class BinanceBtcTrackerBrowserSource
{
    public const array DB_USERS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => BinanceBtcTrackerDbContext::users,
    ];

    public const array DB_ACCOUNT_DELETIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => BinanceBtcTrackerDbContext::accountDeletions,
    ];

    public const array RT_CONNECTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => BinanceBtcTrackerRtContext::connections,
    ];
}
