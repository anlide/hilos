<?php

declare(strict_types=1);

namespace Hilos\Pages\Maintenance;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * Maintenance words page: shows the words of maintenance screens on a Words tab (HIL-1677).
 *
 * Projects register a thin subclass and bind HilosMaintenanceWordsTable in PAGE_TABLES.
 */
abstract class AbstractHilosMaintenanceWordsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_MAINTENANCE_WORDS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array ACTIONS = [];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MAINTENANCE,
    ];
}
