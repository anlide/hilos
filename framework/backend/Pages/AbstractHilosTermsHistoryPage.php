<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;

/** Public Terms history; revision text actions remain owned by the Terms page. */
abstract class AbstractHilosTermsHistoryPage extends AbstractHilosTermsPage
{
    public const string PAGE = HilosPageConstants::HILOS_TERMS_HISTORY;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_TERMS_HISTORY,
    ];

    public const array ACTIONS = [];

    public const array READING_ACTIONS = [];
}
