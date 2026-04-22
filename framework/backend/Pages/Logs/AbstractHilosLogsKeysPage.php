<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Page\PageRouteParams;

/**
 * AbstractHilosLogsKeysPage - Abstract base for Hilos logs by key list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Logs\LogsKeysPage).
 */
abstract class AbstractHilosLogsKeysPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_KEYS;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Page params from route (e.g. ['id' => '123'])
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_KEYS,
            $acceptKey,
            new SignalData(),
        );
    }
}
