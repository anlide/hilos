<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Browser;

use Demo\FileGalleryManager\Database\FileGalleryManagerDbContext;
use Demo\FileGalleryManager\Runtime\View\Context\FileGalleryManagerRtContext;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;

/**
 * DB and RT sources used by this demo's browser configs.
 */
final class FileGalleryManagerBrowserSource
{
    public const array DB_USERS = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => FileGalleryManagerDbContext::users,
    ];

    public const array RT_CONNECTIONS = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => FileGalleryManagerRtContext::connections,
    ];
}
