<?php

declare(strict_types=1);

namespace Hilos\Pages\I18n\Lists;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;

/**
 * AbstractHilosI18nGroupsListPage - Abstract base for Hilos i18n groups list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\I18n\Lists\GroupsListPage).
 */
abstract class AbstractHilosI18nGroupsListPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_I18N_GROUPS;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_I18N_GROUPS,
    ];
}
