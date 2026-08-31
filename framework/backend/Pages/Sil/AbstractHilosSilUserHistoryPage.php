<?php

declare(strict_types=1);

namespace Hilos\Pages\Sil;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosSilUserHistoryPage - Abstract base for Hilos SIL user history.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Sil\SilUserHistoryPage).
 */
abstract class AbstractHilosSilUserHistoryPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SIL_USER_HISTORY;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SIL_USER_HISTORY,
    ];
}
