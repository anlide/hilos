<?php

declare(strict_types=1);

namespace Hilos\Pages\ChangeLog;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosChangeLogTablesPage - Abstract base for Hilos change log tracked tables list.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogTablesPage).
 *
 * TODO: [change-log] Detect schema drift: compare trigger definition (tracked columns) against current config
 *       and flag triggers as outdated when column count or names mismatch.
 */
abstract class AbstractHilosChangeLogTablesPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_CHANGE_LOG_TABLES;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLES,
    ];
}
