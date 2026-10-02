<?php

declare(strict_types=1);

namespace Demo\Tasks\Browser;

use Demo\Tasks\Database\TasksDbContext;
use Demo\Tasks\Runtime\View\Context\TasksRtContext;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;

/**
 * DB and RT sources used by tasks browser configs.
 */
final class TasksBrowserSource
{
    public const array DB_USERS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => TasksDbContext::users,
    ];

    public const array RT_CONNECTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => TasksRtContext::connections,
    ];
}
