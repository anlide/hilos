<?php

declare(strict_types=1);

namespace Demo\Preferans\Browser;

use Demo\Preferans\Database\PreferansDbContext;
use Demo\Preferans\Runtime\View\Context\PreferansRtContext;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;

/**
 * DB and RT sources used by this demo's browser configs.
 */
final class PreferansBrowserSource
{
    public const array DB_USERS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => PreferansDbContext::users,
    ];

    public const array RT_CONNECTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => PreferansRtContext::connections,
    ];
}
