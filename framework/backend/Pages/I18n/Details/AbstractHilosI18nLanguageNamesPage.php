<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\PageReach;
use Hilos\Database\Context\HilosDbContext;

/** Base for the names of one language. */
abstract class AbstractHilosI18nLanguageNamesPage extends AbstractHilosI18nLanguageCodePage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_LANGUAGE_NAMES;

    public const PageReach REACH = PageReach::ROUTE;

    /** @var list<string> Sources of the names table of one language (HIL-1477) */
    public const array READS_DB = [
        ...parent::READS_DB,
        HilosDbContext::locales,
        HilosDbContext::languageNames,
        HilosDbContext::countries,
        HilosDbContext::countryNames,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_LANGUAGE_NAMES,
    ];
}
