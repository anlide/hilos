<?php

declare(strict_types=1);

namespace Hilos\Pages\Billing;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Page\PageRouteParams;

/**
 * AbstractHilosBillingProviderPage - Single payment provider configuration.
 */
abstract class AbstractHilosBillingProviderPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_BILLING_PROVIDER;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_BILLING_PROVIDER,
    ];

    /**
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Page params from route (e.g. providerId)
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_BILLING_PROVIDER,
            $acceptKey,
            new SignalData(),
        );
    }
}
