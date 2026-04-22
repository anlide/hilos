<?php

declare(strict_types=1);

namespace Hilos\Pages\ChangeLog;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Page\PageRouteParams;

/**
 * AbstractHilosChangeLogTablesPage - Abstract base for Hilos change log tracked tables list.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\ChangeLog\ChangeLogTablesPage).
 *
 * TODO: [change-log] Detect schema drift: compare trigger definition (tracked columns) against current config
 *       and flag triggers as outdated when column count or names mismatch.
 */
abstract class AbstractHilosChangeLogTablesPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_CHANGE_LOG_TABLES;

    /**
     * Handle page subscription.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Page params from route
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLES,
            $acceptKey,
            new SignalData(),
        );
    }
}
