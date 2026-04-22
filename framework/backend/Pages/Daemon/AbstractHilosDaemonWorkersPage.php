<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Page\PageRouteParams;

/**
 * AbstractHilosDaemonWorkersPage - Abstract base for Hilos daemon workers list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Daemon\DaemonWorkersPage).
 */
abstract class AbstractHilosDaemonWorkersPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_DAEMON_WORKERS;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DAEMON_WORKERS,
            $acceptKey,
            new SignalData(),
        );
    }
}
