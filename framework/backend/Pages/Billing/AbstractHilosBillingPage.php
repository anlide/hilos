<?php

declare(strict_types=1);

namespace Hilos\Pages\Billing;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosBillingPage - Payment providers hub.
 */
abstract class AbstractHilosBillingPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_BILLING;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_BILLING,
    ];
}
