<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Page\PageRouteParams;

/**
 * AbstractHilosDashboardPage - Abstract base for Hilos dashboard page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\DashboardPage).
 */
abstract class AbstractHilosDashboardPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_DASHBOARD;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_DASHBOARD,
            $acceptKey,
            new SignalData(),
        );
    }
}
