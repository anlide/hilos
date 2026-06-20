<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Browser;

use Demo\SimplePoll\Database\PollDbContext;
use Demo\SimplePoll\Runtime\View\Context\PollRtContext;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;

/**
 * DB and RT sources used by simple-poll browser configs.
 */
final class PollBrowserSource
{
    public const array DB_USERS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => PollDbContext::users,
    ];

    public const array RT_CONNECTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => PollRtContext::connections,
    ];
}
