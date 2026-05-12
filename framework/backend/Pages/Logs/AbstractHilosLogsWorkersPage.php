<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Page\PageRouteParams;

/**
 * AbstractHilosLogsWorkersPage - Abstract base for Hilos logs by worker list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Logs\LogsWorkersPage).
 */
abstract class AbstractHilosLogsWorkersPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_WORKERS;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_WORKERS,
    ];

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_WORKERS,
            $acceptKey,
            new SignalData(),
        );
    }
}
