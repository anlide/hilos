<?php

declare(strict_types=1);

namespace Hilos\Pages\Billing;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * AbstractHilosBillingProviderPage - Single payment provider configuration.
 */
abstract class AbstractHilosBillingProviderPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_BILLING_PROVIDER;

    /**
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route (e.g. providerId)
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_BILLING_PROVIDER,
            $acceptKey,
            new SignalData(),
        );
    }
}
