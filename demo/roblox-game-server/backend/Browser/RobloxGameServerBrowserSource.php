<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Browser;

use Demo\RobloxGameServer\Database\RobloxGameServerDbContext;
use Demo\RobloxGameServer\Runtime\View\Context\RobloxGameServerRtContext;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;

/**
 * DB and RT sources used by this demo's browser configs.
 */
final class RobloxGameServerBrowserSource
{
    public const array DB_USERS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => RobloxGameServerDbContext::users,
    ];

    public const array RT_CONNECTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => RobloxGameServerRtContext::connections,
    ];
}
