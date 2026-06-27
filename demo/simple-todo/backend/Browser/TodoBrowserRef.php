<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Browser;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;

/**
 * Reusable references for simple-todo browser configs.
 */
final class TodoBrowserRef
{
    public const array HILOS_USER_ID = [
        BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
        BrowserRefKey::KEY => HilosPageRouteParams::HILOS_USER_USER_ID,
    ];

    public const array TABLE_HILOS_USER_ID = [
        BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
        BrowserRefKey::KEY => HilosPageRouteParams::HILOS_USER_USER_ID,
    ];
}
