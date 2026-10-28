<?php

declare(strict_types=1);

namespace Hilos\Pages\ChangeLog;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\PageReach;

/** Answers with the shared live-table section under its own route identity. */
abstract class AbstractHilosChangeLogTablePage extends AbstractHilosChangeLogTableContextPage
{
    public const string PAGE = HilosPageConstants::HILOS_CHANGE_LOG_TABLE;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE,
    ];
}
