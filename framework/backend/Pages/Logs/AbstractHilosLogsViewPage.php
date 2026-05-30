<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;

/**
 * AbstractHilosLogsViewPage - Abstract base for Hilos logs viewer page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Logs\LogsViewPage).
 */
abstract class AbstractHilosLogsViewPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS_VIEW;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_VIEW,
    ];
}
