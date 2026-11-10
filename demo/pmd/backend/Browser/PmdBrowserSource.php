<?php

declare(strict_types=1);

namespace Demo\Pmd\Browser;

use Demo\Pmd\Database\PmdDbContext;
use Demo\Pmd\Runtime\View\Context\PmdRtContext;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;

/**
 * DB and RT sources used by this demo's browser configs.
 */
final class PmdBrowserSource
{
    public const array DB_PUSH_SUBSCRIPTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => PmdDbContext::pushSubscriptions,
    ];

    public const array DB_SESSIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => PmdDbContext::sessions,
    ];

    public const array DB_USERS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => PmdDbContext::users,
    ];

    public const array RT_CONNECTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => PmdRtContext::connections,
    ];
}
