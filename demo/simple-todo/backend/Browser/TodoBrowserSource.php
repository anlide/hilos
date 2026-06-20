<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Browser;

use Demo\SimpleTodo\Database\TodoDbContext;
use Demo\SimpleTodo\Runtime\View\Context\TodoRtContext;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;

/**
 * DB and RT sources used by simple-todo browser configs.
 */
final class TodoBrowserSource
{
    public const array DB_USERS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => TodoDbContext::users,
    ];

    public const array RT_CONNECTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => TodoRtContext::connections,
    ];
}
