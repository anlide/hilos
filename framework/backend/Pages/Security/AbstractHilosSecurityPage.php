<?php

declare(strict_types=1);

namespace Hilos\Pages\Security;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosSecurityPage - Security center overview.
 */
abstract class AbstractHilosSecurityPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SECURITY;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SECURITY,
    ];
}
