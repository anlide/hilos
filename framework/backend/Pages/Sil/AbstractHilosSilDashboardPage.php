<?php

declare(strict_types=1);

namespace Hilos\Pages\Sil;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosSilDashboardPage - Abstract base for Hilos System Intelligence Layer dashboard.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Sil\SilDashboardPage).
 */
abstract class AbstractHilosSilDashboardPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SIL;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SIL,
    ];
}
