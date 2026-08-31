<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Lists;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosI18nLanguagesListPage - Abstract base for Hilos i18n languages list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18n\Lists\LanguagesListPage).
 */
abstract class AbstractHilosI18nLanguagesListPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_LANGUAGES;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_LANGUAGES,
    ];
}
