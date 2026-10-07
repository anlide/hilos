<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\PageReach;

/** Base for the locales of one language. */
abstract class AbstractHilosI18nLanguageLocalesPage extends AbstractHilosI18nLanguageCodePage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_LANGUAGE_LOCALES;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_LANGUAGE_LOCALES,
    ];
}
