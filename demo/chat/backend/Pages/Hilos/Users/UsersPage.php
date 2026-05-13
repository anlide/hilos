<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Users;

use Demo\Chat\Tables\ChatTableContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Pages\Users\AbstractHilosUsersPage;

/**
 * UsersPage - Hilos users list page implementation for demo.
 *
 * Browser subscription emits the Hilos users table rows and incremental row
 * changes through the page browser payload.
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
