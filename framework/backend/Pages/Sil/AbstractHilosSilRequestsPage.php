<?php

declare(strict_types=1);

namespace Hilos\Pages\Sil;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;

/**
 * AbstractHilosSilRequestsPage - Abstract base for Hilos SIL requests list.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Sil\SilRequestsPage).
 */
abstract class AbstractHilosSilRequestsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SIL_REQUESTS;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param array<string, string> $params Page params from route
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SIL_REQUESTS,
            $acceptKey,
            new SignalData(),
        );
    }
}
