<?php

declare(strict_types=1);

namespace Hilos\Pages\Appearance;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/** Read-only admin page for the two installation theme settings. */
abstract class AbstractHilosAppearancePage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_APPEARANCE;
    public const PageReach REACH = PageReach::ROUTE;
    public const array ACTIONS = [];
    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_APPEARANCE,
    ];
}
