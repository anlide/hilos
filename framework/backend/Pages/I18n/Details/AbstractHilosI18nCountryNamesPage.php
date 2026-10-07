<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\PageReach;

/** Base for the names of one country. */
abstract class AbstractHilosI18nCountryNamesPage extends AbstractHilosI18nCountryCodePage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_COUNTRY_NAMES;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_COUNTRY_NAMES,
    ];
}
