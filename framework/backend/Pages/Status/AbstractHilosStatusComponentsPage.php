<?php

declare(strict_types=1);

namespace Hilos\Pages\Status;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Runtime\State\Item\HilosStatusComponent;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/** Read-only component window; projects bind the framework table in PAGE_TABLES. */
abstract class AbstractHilosStatusComponentsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_STATUS_COMPONENTS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_STATUS_COMPONENTS,
    ];

    public const array READS_DB = [...parent::READS_DB, HilosDbContext::statusIncidents];

    public const array READS_RT = [...parent::READS_RT, HilosStatusComponent::RT_COLLECTION, ProtectedModeRuntime::RT_ITEM];
}
