<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Browser;

use Demo\HilosWebsite\Database\HilosWebsiteDbContext;
use Demo\HilosWebsite\Runtime\View\Context\HilosWebsiteRtContext;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;

/**
 * DB and RT sources used by this demo's browser configs.
 */
final class HilosWebsiteBrowserSource
{
    public const array DB_USERS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosWebsiteDbContext::users,
    ];

    public const array RT_CONNECTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => HilosWebsiteRtContext::connections,
    ];
}
