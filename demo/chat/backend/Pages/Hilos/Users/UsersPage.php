<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Users;

use Demo\Chat\Pages\AdminUsersPage;
use Demo\Chat\Projection\Page\HilosUsersPageProjection;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Pages\Users\AbstractHilosUsersPage;

/**
 * UsersPage - Hilos users list page implementation for demo.
 *
 * The initial users table snapshot and table mutation broadcasts are produced
 * by {@see HilosUsersPageProjection}, mirroring the admin users page projection
 * (see {@see AdminUsersPage}).
 */
final class UsersPage extends AbstractHilosUsersPage
{
    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_USERS,
        BrowserConfigKey::TABLES => [
            ChatTableContext::hilosUsers => [],
        ],
    ];
}
