<?php

declare(strict_types=1);

namespace Hilos\Pages\Billing;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * AbstractHilosBillingPage - Payment providers hub.
 */
abstract class AbstractHilosBillingPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_BILLING;

    /**
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_BILLING,
            $acceptKey,
            new SignalData(),
        );
    }
}
