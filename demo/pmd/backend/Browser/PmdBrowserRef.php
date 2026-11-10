<?php

declare(strict_types=1);

namespace Demo\Pmd\Browser;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;

/**
 * Reusable references for this demo's browser configs.
 */
final class PmdBrowserRef
{
    public const array ACCEPT_KEY = [
        BrowserRefKey::TYPE => BrowserRefType::ACCEPT_KEY,
    ];

    public const array TABLE_ACCEPT_KEY = [
        BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
        BrowserRefKey::KEY => BrowserRuntimeParam::ACCEPT_KEY,
    ];

    public const array HILOS_USER_ID = [
        BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
        BrowserRefKey::KEY => HilosPageRouteParams::HILOS_USER_USER_ID,
    ];
}
