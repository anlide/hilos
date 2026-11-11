<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel\Browser;

use Demo\SolarSystemModel\Database\SolarSystemModelDbContext;
use Demo\SolarSystemModel\Runtime\View\Context\SolarSystemModelRtContext;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;

/**
 * DB and RT sources used by this demo's browser configs.
 */
final class SolarSystemModelBrowserSource
{
    public const array DB_USERS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => SolarSystemModelDbContext::users,
    ];

    public const array RT_CONNECTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => SolarSystemModelRtContext::connections,
    ];
}
