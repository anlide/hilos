<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Page\PageRouteParams;

/**
 * AbstractHilosCommunicationsPage - Hilos communications channels hub.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Communications\CommunicationsPage).
 */
abstract class AbstractHilosCommunicationsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_COMMUNICATIONS;

    /**
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Page params from route
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS,
            $acceptKey,
            new SignalData(),
        );
    }
}
