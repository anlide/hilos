<?php

declare(strict_types=1);

namespace Demo\Polls\Browser;

use Demo\Polls\Database\PollsDbContext;
use Demo\Polls\Runtime\View\Context\PollsRtContext;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;

/**
 * DB and RT sources used by polls browser configs.
 */
final class PollsBrowserSource
{
    public const array DB_USERS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => PollsDbContext::users,
    ];

    public const array RT_CONNECTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => PollsRtContext::connections,
    ];
}
