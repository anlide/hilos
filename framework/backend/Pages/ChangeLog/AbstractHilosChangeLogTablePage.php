<?php

declare(strict_types=1);

namespace Hilos\Pages\ChangeLog;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Page\PageRouteParams;

/**
 * AbstractHilosChangeLogTablePage - Abstract base for Hilos change log single-table detail.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogTablePage).
 */
abstract class AbstractHilosChangeLogTablePage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_CHANGE_LOG_TABLE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE,
    ];

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Page params from route (e.g. ['tableId' => 'user'])
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE,
            $acceptKey,
            new SignalData(),
        );
    }
}
