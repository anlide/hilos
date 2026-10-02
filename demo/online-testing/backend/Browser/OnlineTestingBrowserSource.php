<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Browser;

use Demo\OnlineTesting\Database\OnlineTestingDbContext;
use Demo\OnlineTesting\Runtime\View\Context\OnlineTestingRtContext;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;

/**
 * DB and RT sources used by this demo's browser configs.
 */
final class OnlineTestingBrowserSource
{
    public const array DB_USERS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => OnlineTestingDbContext::users,
    ];

    public const array DB_ACCOUNT_DELETIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => OnlineTestingDbContext::accountDeletions,
    ];

    public const array RT_CONNECTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => OnlineTestingRtContext::connections,
    ];
}
