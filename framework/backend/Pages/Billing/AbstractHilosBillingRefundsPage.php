<?php

declare(strict_types=1);

namespace Hilos\Pages\Billing;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Page\PageRouteParams;

/**
 * AbstractHilosBillingRefundsPage - Refunds list for a provider.
 */
abstract class AbstractHilosBillingRefundsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_BILLING_REFUNDS;

    /**
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Page params from route (e.g. providerId)
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_BILLING_REFUNDS,
            $acceptKey,
            new SignalData(),
        );
    }
}
