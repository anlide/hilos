<?php

declare(strict_types=1);

namespace Hilos\Pages\Billing;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosBillingPaymentsPage - Payments list for a provider.
 */
abstract class AbstractHilosBillingPaymentsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_BILLING_PAYMENTS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_BILLING_PAYMENTS,
    ];
}
