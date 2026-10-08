<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\PageReach;
use Hilos\Database\Context\HilosDbContext;

/**
 * AbstractHilosI18nCountryPage - Abstract base for Hilos i18n country page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18n\Details\CountryDetailPage).
 */
abstract class AbstractHilosI18nCountryPage extends AbstractHilosI18nCountryCodePage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_COUNTRY;

    public const PageReach REACH = PageReach::ROUTE;

    /** @var list<string> Sources of the main country card */
    public const array READS_DB = [
        ...parent::READS_DB,
        HilosDbContext::locales,
        HilosDbContext::countryNames,
        HilosDbContext::languages,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_COUNTRY,
    ];
}
