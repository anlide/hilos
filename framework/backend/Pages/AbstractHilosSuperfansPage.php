<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Agent\Hilos\AbstractHilosSuperfanAgent;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosSuperfansPage - Abstract base for Hilos superfans page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\SuperfansPage).
 *
 * @property AbstractHilosSuperfanAgent $agent
 */
abstract class AbstractHilosSuperfansPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SUPERFANS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SUPERFANS,
    ];
}
