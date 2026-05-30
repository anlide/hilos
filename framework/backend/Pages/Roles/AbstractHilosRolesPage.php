<?php

declare(strict_types=1);

namespace Hilos\Pages\Roles;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;

/**
 * AbstractHilosRolesPage - Abstract base for Hilos roles list page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Roles\RolesPage).
 */
abstract class AbstractHilosRolesPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_ROLES;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ROLES,
    ];
}
