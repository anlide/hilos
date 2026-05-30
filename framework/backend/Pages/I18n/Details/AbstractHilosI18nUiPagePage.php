<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Details;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;

/**
 * AbstractHilosI18nUiPagePage - Abstract base for Hilos i18n UI page detail page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18n\Details\UiPageDetailPage).
 */
abstract class AbstractHilosI18nUiPagePage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_UI_PAGE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_UI_PAGE,
    ];
}
