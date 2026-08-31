<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosAnalyticsPage - Abstract base for Hilos analytics page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\AnalyticsPage).
 */
abstract class AbstractHilosAnalyticsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_ANALYTICS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ANALYTICS,
    ];
}
