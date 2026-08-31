<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Lists;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosI18nUiPagesListPage - Abstract base for Hilos i18n UI pages list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18n\Lists\UiPagesListPage).
 */
abstract class AbstractHilosI18nUiPagesListPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_UI_PAGES;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_UI_PAGES,
    ];
}
