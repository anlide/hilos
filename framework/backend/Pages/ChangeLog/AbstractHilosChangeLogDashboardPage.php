<?php

declare(strict_types=1);

namespace Hilos\Pages\ChangeLog;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosChangeLogDashboardPage - Abstract base for Hilos change log dashboard.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogDashboardPage).
 *
 * TODO: [change-log] Provide a reusable component/API to display change history for a specific record+field
 *       inline in entity edit forms (not a dedicated Hilos viewer page).
 *
 * TODO: [change-log] Implement retention cleanup mechanism. Retention must be explicitly configured:
 *       forever | 100 days | 1 year | 10 years. Add a cron job or daemon task to purge expired entries.
 *
 * TODO: [change-log] Implement dry-run sync: generate SQL for triggers that would be created/dropped/recreated,
 *       display to admin for review before executing.
 */
abstract class AbstractHilosChangeLogDashboardPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_CHANGE_LOG;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG,
    ];
}
