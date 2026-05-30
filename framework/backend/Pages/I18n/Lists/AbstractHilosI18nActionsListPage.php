<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Lists;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;

/**
 * AbstractHilosI18nActionsListPage - Abstract base for Hilos i18n actions list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18n\Lists\ActionsListPage).
 */
abstract class AbstractHilosI18nActionsListPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_ACTIONS;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_ACTIONS,
    ];
}
