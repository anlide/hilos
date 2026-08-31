<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Agent\Hilos\AbstractHilosGuardianAgent;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosGuardianPage - Abstract base for Hilos guardian page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\GuardianPage).
 *
 * @property AbstractHilosGuardianAgent $agent
 */
abstract class AbstractHilosGuardianPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_GUARDIAN;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_GUARDIAN,
    ];
}
