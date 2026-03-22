<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * AbstractHilosCommunicationsDeliveriesPage - Channel delivery log (stub subscription).
 */
abstract class AbstractHilosCommunicationsDeliveriesPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES;

    /**
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route (e.g. channelId)
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS_DELIVERIES,
            $acceptKey,
            new SignalData(),
        );
    }
}
