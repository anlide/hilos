<?php

declare(strict_types=1);

namespace Hilos\Pages\Sil;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Page\PageRouteParams;

/**
 * AbstractHilosSilDashboardPage - Abstract base for Hilos System Intelligence Layer dashboard.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Sil\SilDashboardPage).
 */
abstract class AbstractHilosSilDashboardPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SIL;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Page params from route
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SIL,
            $acceptKey,
            new SignalData(),
        );
    }
}
