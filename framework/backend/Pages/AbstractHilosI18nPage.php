<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;

/**
 * AbstractHilosI18nPage - Abstract base for Hilos i18n page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18nPage).
 */
abstract class AbstractHilosI18nPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N,
    ];
}
