<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosLogsKeysPage - Abstract base for Hilos logs by key list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Logs\LogsKeysPage).
 */
abstract class AbstractHilosLogsKeysPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_KEYS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_KEYS,
    ];
}
