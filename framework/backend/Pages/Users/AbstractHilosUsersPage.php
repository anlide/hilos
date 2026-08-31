<?php

declare(strict_types=1);

namespace Hilos\Pages\Users;

use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;

/**
 * Base class for the framework Hilos users-list page.
 *
 * Subscribe behavior is supplied by project browser configs or page overrides.
 * The base page key is shared with the browser context so projects can return
 * page-shaped users snapshots through the default subscription handler.
 */
abstract class AbstractHilosUsersPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_USERS;

    public const PageReach REACH = PageReach::ROUTE;
}
