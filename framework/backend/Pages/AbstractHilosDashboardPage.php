<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;

/**
 * AbstractHilosDashboardPage - Abstract base for Hilos dashboard page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\DashboardPage).
 */
abstract class AbstractHilosDashboardPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_DASHBOARD;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DASHBOARD,
    ];
}
