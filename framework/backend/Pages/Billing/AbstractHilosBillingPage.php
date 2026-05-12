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
 * AbstractHilosBillingPage - Payment providers hub.
 */
abstract class AbstractHilosBillingPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_BILLING;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_BILLING,
    ];

    /**
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Page params from route
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_BILLING,
            $acceptKey,
            new SignalData(),
        );
    }
}
