<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Translate;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * AbstractHilosI18nTranslateGroupScreenPage - Abstract base for Hilos i18n translate group page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18n\Translate\TranslateGroupPage).
 */
abstract class AbstractHilosI18nTranslateGroupScreenPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_TRANSLATE_GROUP;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_GROUP,
    ];
}
