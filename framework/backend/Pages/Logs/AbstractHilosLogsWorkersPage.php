<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;

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
}
