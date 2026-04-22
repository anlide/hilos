<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Page\PageRouteParams;

/**
 * AbstractHilosLogsRotationsPage - Abstract base for Hilos logs rotation history page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Logs\LogsRotationsPage).
 */
abstract class AbstractHilosLogsRotationsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_ROTATIONS;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_ROTATIONS,
            $acceptKey,
            new SignalData(),
        );
    }
}
