<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosI18nLanguagePage - Abstract base for Hilos i18n language page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18n\Details\LanguageDetailPage).
 */
abstract class AbstractHilosI18nLanguagePage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_LANGUAGE;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_LANGUAGE,
    ];
}
